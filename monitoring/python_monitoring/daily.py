from __future__ import annotations

from .db import Database
from .table_names import table_name, table_sql


def _ensure_daily_stats_calc_columns(db: Database, daily_table: str) -> None:
    definitions = {
        "total_seconds": "INT NULL",
        "offline_seconds": "INT NULL",
        "degraded_seconds": "INT NULL",
        "maintenance_seconds": "INT NULL",
        "availability_ratio": "DECIMAL(7,4) NULL",
        "health_score": "DECIMAL(7,4) NULL",
        "calc_method": "VARCHAR(24) NULL",
    }
    for column, ddl in definitions.items():
        exists = db.query_one(
            """
            SELECT 1 AS present
            FROM INFORMATION_SCHEMA.COLUMNS
            WHERE TABLE_SCHEMA = DATABASE()
              AND TABLE_NAME = %s
              AND COLUMN_NAME = %s
            LIMIT 1
            """,
            (daily_table, column),
        )
        if exists:
            continue
        try:
            db.execute(f"ALTER TABLE `{daily_table}` ADD COLUMN {column} {ddl}")
        except Exception:
            pass


def upsert_daily_stats(
    db: Database,
    site_id: int,
    date_value: str,
    avg_response_time: float,
    minutes_offline: int,
    total_seconds: int,
    offline_seconds: int,
    degraded_seconds: int,
    maintenance_seconds: int,
    availability_ratio: float | None,
    health_score: float | None,
    calc_method: str,
    *,
    daily_table_sql: str,
) -> None:
    db.execute(
        f"""
        INSERT INTO {daily_table_sql}
            (site_id, date, avg_response_time, minutes_offline, total_seconds, offline_seconds, degraded_seconds, maintenance_seconds, availability_ratio, health_score, calc_method, created_at)
        VALUES (%s, %s, %s, %s, %s, %s, %s, %s, %s, %s, %s, NOW())
        ON DUPLICATE KEY UPDATE
            avg_response_time = VALUES(avg_response_time),
            minutes_offline = VALUES(minutes_offline),
            total_seconds = VALUES(total_seconds),
            offline_seconds = VALUES(offline_seconds),
            degraded_seconds = VALUES(degraded_seconds),
            maintenance_seconds = VALUES(maintenance_seconds),
            availability_ratio = VALUES(availability_ratio),
            health_score = VALUES(health_score),
            calc_method = VALUES(calc_method),
            created_at = NOW()
        """,
        (
            site_id,
            date_value,
            int(round(avg_response_time)),
            int(minutes_offline),
            int(total_seconds),
            int(offline_seconds),
            int(degraded_seconds),
            int(maintenance_seconds),
            availability_ratio,
            health_score,
            calc_method,
        ),
    )


def run_daily_job(db: Database, cfg: dict | None = None) -> dict:
    cfg = cfg or {}
    hourly_table = table_name("hourly_stats", cfg)
    daily_table = table_name("daily_stats", cfg)
    hourly_table_sql = table_sql("hourly_stats", cfg)
    daily_table_sql = table_sql("daily_stats", cfg)
    _ensure_daily_stats_calc_columns(db, daily_table)

    processed = 0
    bad_data = 0
    sites = db.query_all("SELECT id FROM sites")

    for site in sites:
        site_id = int(site.get("id") or 0)
        if site_id <= 0:
            continue

        dates = db.query_all(
            f"""
            SELECT DISTINCT DATE(date) AS date
            FROM {hourly_table_sql}
            WHERE site_id = %s AND DATE(date) < CURDATE()
            ORDER BY DATE(date) ASC
            """,
            (site_id,),
        )
        for row in dates:
            date_value = str(row.get("date") or "")
            if not date_value:
                bad_data += 1
                continue

            agg = db.query_one(
                f"""
                SELECT
                    AVG(avg_response_time) AS avg_response_time,
                    SUM(minutes_offline) AS total_minutes_offline,
                    SUM(COALESCE(total_seconds, 3600)) AS total_seconds,
                    SUM(COALESCE(offline_seconds, COALESCE(minutes_offline, 0) * 60)) AS offline_seconds,
                    SUM(COALESCE(degraded_seconds, 0)) AS degraded_seconds,
                    SUM(COALESCE(maintenance_seconds, 0)) AS maintenance_seconds,
                    SUM(CASE WHEN COALESCE(calc_method, 'legacy') = 'time_weighted' THEN 1 ELSE 0 END) AS weighted_rows,
                    COUNT(*) AS row_count,
                    SUM(
                        CASE
                            WHEN health_score IS NULL THEN 0
                            ELSE health_score * GREATEST(0, COALESCE(total_seconds, 3600) - COALESCE(maintenance_seconds, 0))
                        END
                    ) AS weighted_health_numerator,
                    SUM(
                        CASE
                            WHEN health_score IS NULL THEN 0
                            ELSE GREATEST(0, COALESCE(total_seconds, 3600) - COALESCE(maintenance_seconds, 0))
                        END
                    ) AS weighted_health_denominator
                FROM {hourly_table_sql}
                WHERE site_id = %s AND DATE(date) = %s
                """,
                (site_id, date_value),
            )
            if not agg:
                bad_data += 1
                continue

            avg_response_time = float(agg.get("avg_response_time") or 0.0)
            total_minutes_offline = int(agg.get("total_minutes_offline") or 0)
            total_seconds = int(agg.get("total_seconds") or 0)
            offline_seconds = int(agg.get("offline_seconds") or 0)
            degraded_seconds = int(agg.get("degraded_seconds") or 0)
            maintenance_seconds = int(agg.get("maintenance_seconds") or 0)
            weighted_rows = int(agg.get("weighted_rows") or 0)
            row_count = int(agg.get("row_count") or 0)
            weighted_health_numerator = float(agg.get("weighted_health_numerator") or 0.0)
            weighted_health_denominator = float(agg.get("weighted_health_denominator") or 0.0)

            if total_seconds <= 0 and row_count > 0:
                total_seconds = row_count * 3600

            denominator = total_seconds - maintenance_seconds
            if denominator <= 0:
                availability_ratio = None
                health_score = None
            else:
                availability_ratio = round(max(0.0, min(1.0, (denominator - offline_seconds) / denominator)), 4)

            if weighted_health_denominator > 0:
                health_score = round(max(0.0, min(1.0, weighted_health_numerator / weighted_health_denominator)), 4)
            elif denominator <= 0:
                health_score = None
            else:
                health_score = availability_ratio

            calc_method = "time_weighted" if weighted_rows > 0 else "legacy"
            if total_minutes_offline <= 0 and offline_seconds > 0:
                total_minutes_offline = int(round(offline_seconds / 60.0))

            try:
                upsert_daily_stats(
                    db,
                    site_id,
                    date_value,
                    avg_response_time,
                    total_minutes_offline,
                    total_seconds,
                    offline_seconds,
                    degraded_seconds,
                    maintenance_seconds,
                    availability_ratio,
                    health_score,
                    calc_method,
                    daily_table_sql=daily_table_sql,
                )
                processed += 1
            except Exception:
                bad_data += 1

    return {
        "ok": True,
        "processed": processed,
        "bad_data": bad_data,
        "tables": {"hourly_stats": hourly_table, "daily_stats": daily_table},
    }
