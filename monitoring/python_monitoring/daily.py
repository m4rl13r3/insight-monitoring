from __future__ import annotations

import json
from datetime import timedelta

from .aggregation_state import aggregation_cutoff, aggregation_job_name, mark_aggregation_success
from .db import Database
from .table_names import table_name, table_sql


def _ensure_daily_stats_calc_columns(db: Database, daily_table: str) -> None:
    definitions = {
        "total_seconds": "INT NULL",
        "offline_seconds": "INT NULL",
        "degraded_seconds": "INT NULL",
        "maintenance_seconds": "INT NULL",
        "unknown_seconds": "INT NOT NULL DEFAULT 0",
        "sample_count": "INT NOT NULL DEFAULT 0",
        "response_time_sum": "DECIMAL(16,3) NOT NULL DEFAULT 0",
        "availability_ratio": "DECIMAL(7,4) NULL",
        "availability_basis_seconds": "INT NOT NULL DEFAULT 0",
        "health_score": "DECIMAL(7,4) NULL",
        "calc_method": "VARCHAR(24) NULL",
        "method_details": "JSON NULL",
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
    unknown_seconds: int,
    sample_count: int,
    response_time_sum: float,
    availability_ratio: float | None,
    availability_basis_seconds: int,
    health_score: float | None,
    calc_method: str,
    method_details: dict,
    *,
    daily_table_sql: str,
) -> None:
    db.execute(
        f"""
        INSERT INTO {daily_table_sql}
            (site_id, date, avg_response_time, minutes_offline, total_seconds, offline_seconds, degraded_seconds, maintenance_seconds, unknown_seconds, sample_count, response_time_sum, availability_ratio, availability_basis_seconds, health_score, calc_method, method_details, created_at)
        VALUES (%s, %s, %s, %s, %s, %s, %s, %s, %s, %s, %s, %s, %s, %s, %s, %s, NOW())
        ON DUPLICATE KEY UPDATE
            avg_response_time = VALUES(avg_response_time),
            minutes_offline = VALUES(minutes_offline),
            total_seconds = VALUES(total_seconds),
            offline_seconds = VALUES(offline_seconds),
            degraded_seconds = VALUES(degraded_seconds),
            maintenance_seconds = VALUES(maintenance_seconds),
            unknown_seconds = VALUES(unknown_seconds),
            sample_count = VALUES(sample_count),
            response_time_sum = VALUES(response_time_sum),
            availability_ratio = VALUES(availability_ratio),
            availability_basis_seconds = VALUES(availability_basis_seconds),
            health_score = VALUES(health_score),
            calc_method = VALUES(calc_method),
            method_details = VALUES(method_details),
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
            int(unknown_seconds),
            int(sample_count),
            float(response_time_sum),
            availability_ratio,
            int(availability_basis_seconds),
            health_score,
            calc_method,
            json.dumps(method_details, ensure_ascii=False, separators=(",", ":")),
        ),
    )


def run_daily_job(db: Database, cfg: dict | None = None) -> dict:
    cfg = cfg or {}
    hourly_table = table_name("hourly_stats", cfg)
    daily_table = table_name("daily_stats", cfg)
    hourly_table_sql = table_sql("hourly_stats", cfg)
    daily_table_sql = table_sql("daily_stats", cfg)
    _ensure_daily_stats_calc_columns(db, daily_table)
    try:
        reprocess_hours = int(str(cfg.get("aggregation_reprocess_hours", "2")).strip())
    except Exception:
        reprocess_hours = 2
    reprocess_days = max(2, min(31, (max(1, reprocess_hours) + 23) // 24 + 1))
    job_name = aggregation_job_name("daily", cfg)
    cutoff = aggregation_cutoff(
        db,
        job_name,
        timedelta(days=reprocess_days),
        timedelta(days=1),
    )

    processed = 0
    bad_data = 0
    sites = db.query_all("SELECT id FROM sites")

    for site in sites:
        site_id = int(site.get("id") or 0)
        if site_id <= 0:
            continue

        cutoff_sql = ""
        params: tuple[object, ...] = (site_id,)
        if cutoff is not None:
            cutoff_sql = "AND DATE(date) >= %s"
            params = (site_id, cutoff.strftime("%Y-%m-%d"))
        dates = db.query_all(
            f"""
            SELECT DISTINCT DATE(date) AS date
            FROM {hourly_table_sql}
            WHERE site_id = %s AND DATE(date) < CURDATE()
              {cutoff_sql}
            ORDER BY DATE(date) ASC
            """,
            params,
        )
        for row in dates:
            date_value = str(row.get("date") or "")
            if not date_value:
                bad_data += 1
                continue

            agg = db.query_one(
                f"""
                SELECT
                    SUM(CASE WHEN COALESCE(sample_count, 0) > 0 THEN response_time_sum ELSE COALESCE(avg_response_time, 0) END) AS response_time_sum,
                    SUM(CASE WHEN COALESCE(sample_count, 0) > 0 THEN sample_count WHEN avg_response_time IS NOT NULL THEN 1 ELSE 0 END) AS sample_count,
                    SUM(minutes_offline) AS total_minutes_offline,
                    SUM(COALESCE(total_seconds, 3600)) AS total_seconds,
                    SUM(COALESCE(offline_seconds, COALESCE(minutes_offline, 0) * 60)) AS offline_seconds,
                    SUM(COALESCE(degraded_seconds, 0)) AS degraded_seconds,
                    SUM(COALESCE(maintenance_seconds, 0)) AS maintenance_seconds,
                    SUM(COALESCE(unknown_seconds, 0)) AS unknown_seconds,
                    COUNT(DISTINCT COALESCE(calc_method, 'legacy')) AS method_count,
                    MIN(COALESCE(calc_method, 'legacy')) AS single_method,
                    COUNT(*) AS row_count,
                    SUM(
                        CASE
                            WHEN availability_ratio IS NULL THEN 0
                            ELSE availability_ratio * COALESCE(NULLIF(availability_basis_seconds, 0), GREATEST(0, COALESCE(total_seconds, 3600) - COALESCE(maintenance_seconds, 0) - COALESCE(unknown_seconds, 0)))
                        END
                    ) AS availability_numerator,
                    SUM(
                        CASE
                            WHEN availability_ratio IS NULL THEN 0
                            ELSE COALESCE(NULLIF(availability_basis_seconds, 0), GREATEST(0, COALESCE(total_seconds, 3600) - COALESCE(maintenance_seconds, 0) - COALESCE(unknown_seconds, 0)))
                        END
                    ) AS availability_basis_seconds,
                    SUM(
                        CASE
                            WHEN health_score IS NULL THEN 0
                            ELSE health_score * COALESCE(NULLIF(availability_basis_seconds, 0), GREATEST(0, COALESCE(total_seconds, 3600) - COALESCE(maintenance_seconds, 0) - COALESCE(unknown_seconds, 0)))
                        END
                    ) AS health_numerator,
                    SUM(
                        CASE
                            WHEN health_score IS NULL THEN 0
                            ELSE COALESCE(NULLIF(availability_basis_seconds, 0), GREATEST(0, COALESCE(total_seconds, 3600) - COALESCE(maintenance_seconds, 0) - COALESCE(unknown_seconds, 0)))
                        END
                    ) AS health_basis
                FROM {hourly_table_sql}
                WHERE site_id = %s AND DATE(date) = %s
                """,
                (site_id, date_value),
            )
            if not agg:
                bad_data += 1
                continue

            response_time_sum = float(agg.get("response_time_sum") or 0.0)
            sample_count = int(agg.get("sample_count") or 0)
            avg_response_time = response_time_sum / sample_count if sample_count > 0 else 0.0
            total_minutes_offline = int(agg.get("total_minutes_offline") or 0)
            total_seconds = int(agg.get("total_seconds") or 0)
            offline_seconds = int(agg.get("offline_seconds") or 0)
            degraded_seconds = int(agg.get("degraded_seconds") or 0)
            maintenance_seconds = int(agg.get("maintenance_seconds") or 0)
            unknown_seconds = int(agg.get("unknown_seconds") or 0)
            method_count = int(agg.get("method_count") or 0)
            single_method = str(agg.get("single_method") or "legacy")
            row_count = int(agg.get("row_count") or 0)
            availability_numerator = float(agg.get("availability_numerator") or 0.0)
            availability_basis_seconds = int(agg.get("availability_basis_seconds") or 0)
            health_numerator = float(agg.get("health_numerator") or 0.0)
            health_basis = float(agg.get("health_basis") or 0.0)

            if total_seconds <= 0 and row_count > 0:
                total_seconds = row_count * 3600

            if availability_basis_seconds <= 0:
                availability_ratio = None
            else:
                availability_ratio = round(max(0.0, min(1.0, availability_numerator / availability_basis_seconds)), 4)

            if health_basis > 0:
                health_score = round(max(0.0, min(1.0, health_numerator / health_basis)), 4)
            elif availability_basis_seconds <= 0:
                health_score = None
            else:
                health_score = availability_ratio

            calc_method = single_method if method_count == 1 else "mixed"
            method_details = {"version": 1, "hour_count": row_count, "method_count": method_count}
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
                    unknown_seconds,
                    sample_count,
                    response_time_sum,
                    availability_ratio,
                    availability_basis_seconds,
                    health_score,
                    calc_method,
                    method_details,
                    daily_table_sql=daily_table_sql,
                )
                processed += 1
            except Exception:
                bad_data += 1

    if bad_data == 0:
        mark_aggregation_success(db, job_name)

    return {
        "ok": True,
        "processed": processed,
        "bad_data": bad_data,
        "tables": {"hourly_stats": hourly_table, "daily_stats": daily_table},
        "reprocess_from": cutoff.strftime("%Y-%m-%d %H:%M:%S") if cutoff is not None else None,
    }
