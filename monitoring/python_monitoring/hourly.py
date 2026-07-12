from __future__ import annotations

from datetime import datetime, timedelta

from .db import Database
from .table_names import table_name, table_sql


SUPPORTED_CALC_METHODS = {"inherit", "legacy", "time_weighted"}


def _build_binary_sequence(statuses: list[int]) -> str:
    return "".join("1" if s else "0" for s in statuses)


def _normalize_calc_method(value: str) -> str:
    method = (value or "").strip().lower()
    return method if method in SUPPORTED_CALC_METHODS else "inherit"


def _parse_dt(value: object) -> datetime | None:
    if value is None:
        return None
    if isinstance(value, datetime):
        return value
    raw = str(value).strip()
    if raw == "":
        return None
    for fmt in ("%Y-%m-%d %H:%M:%S", "%Y-%m-%dT%H:%M:%S"):
        try:
            return datetime.strptime(raw[:19], fmt)
        except ValueError:
            continue
    return None


def _status_bucket(value: object) -> str:
    state = str(value or "").strip().lower()
    if state in {"online", "up", "ok", "success", "yes"}:
        return "online"
    if state in {"maintenance"}:
        return "maintenance"
    if state in {"partial", "partially", "degraded", "warn"}:
        return "degraded"
    if state in {"offline", "down", "error", "failed", "timeout", "no"}:
        return "offline"
    return "unknown"


def _ensure_sites_runtime_columns(db: Database) -> None:
    definitions = {
        "probe_interval_sec": "INT NOT NULL DEFAULT 60",
        "calc_method": "VARCHAR(24) NOT NULL DEFAULT 'inherit'",
        "http_methods": "VARCHAR(128) NOT NULL DEFAULT 'GET,POST,PUT,HEAD,DELETE,PATCH,OPTIONS'",
        "http_redirect_modes": "VARCHAR(32) NOT NULL DEFAULT 'follow,no_follow'",
        "http_primary_method": "VARCHAR(16) NOT NULL DEFAULT 'GET'",
        "http_primary_redirect": "VARCHAR(16) NOT NULL DEFAULT 'follow'",
    }
    for column, ddl in definitions.items():
        exists = db.query_one(
            """
            SELECT 1 AS present
            FROM INFORMATION_SCHEMA.COLUMNS
            WHERE TABLE_SCHEMA = DATABASE()
              AND TABLE_NAME = 'sites'
              AND COLUMN_NAME = %s
            LIMIT 1
            """,
            (column,),
        )
        if exists:
            continue
        try:
            db.execute(f"ALTER TABLE `sites` ADD COLUMN {column} {ddl}")
        except Exception:
            pass


def _ensure_hourly_stats_calc_columns(db: Database, hourly_table: str) -> None:
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
            (hourly_table, column),
        )
        if exists:
            continue
        try:
            db.execute(f"ALTER TABLE `{hourly_table}` ADD COLUMN {column} {ddl}")
        except Exception:
            pass


def _ensure_calc_settings_table(db: Database) -> dict:
    db.execute(
        """
        CREATE TABLE IF NOT EXISTS monitoring_calc_settings (
            singleton_id TINYINT NOT NULL DEFAULT 1,
            switch_at DATETIME NOT NULL,
            default_calc_method VARCHAR(24) NOT NULL DEFAULT 'time_weighted',
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (singleton_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
        """
    )
    row = db.query_one(
        """
        SELECT switch_at, default_calc_method
        FROM monitoring_calc_settings
        WHERE singleton_id = 1
        LIMIT 1
        """
    )
    if row:
        switch_dt = _parse_dt(row.get("switch_at"))
        default_method = str(row.get("default_calc_method") or "time_weighted").strip().lower()
        if default_method not in {"legacy", "time_weighted"}:
            default_method = "time_weighted"
        return {
            "switch_at": switch_dt,
            "default_calc_method": default_method,
        }

    switch_dt = (datetime.now().replace(hour=0, minute=0, second=0, microsecond=0) + timedelta(days=1))
    switch_value = switch_dt.strftime("%Y-%m-%d %H:%M:%S")
    db.execute(
        """
        INSERT INTO monitoring_calc_settings (singleton_id, switch_at, default_calc_method)
        VALUES (1, %s, 'time_weighted')
        ON DUPLICATE KEY UPDATE switch_at = switch_at
        """,
        (switch_value,),
    )
    return {
        "switch_at": switch_dt,
        "default_calc_method": "time_weighted",
    }


def _effective_calc_method(site_calc_method: str, slot_start: datetime, switch_at: datetime | None, default_calc_method: str) -> str:
    site_method = _normalize_calc_method(site_calc_method)
    if site_method in {"legacy", "time_weighted"}:
        return site_method
    default_method = str(default_calc_method or "time_weighted").strip().lower()
    if default_method not in {"legacy", "time_weighted"}:
        default_method = "time_weighted"
    if switch_at is None:
        return default_method
    if slot_start >= switch_at:
        return default_method
    return "legacy"


def _compute_weighted_hour_metrics(
    db: Database,
    site_id: int,
    slot_start: datetime,
    slot_end: datetime,
    *,
    probes_table_sql: str,
) -> dict:
    prev = db.query_one(
        f"""
        SELECT status
        FROM {probes_table_sql}
        WHERE site_id = %s
          AND checked_at < %s
        ORDER BY checked_at DESC
        LIMIT 1
        """,
        (site_id, slot_start.strftime("%Y-%m-%d %H:%M:%S")),
    )
    previous_bucket = _status_bucket(prev.get("status")) if prev else "unknown"

    rows = db.query_all(
        f"""
        SELECT checked_at, status
        FROM {probes_table_sql}
        WHERE site_id = %s
          AND checked_at >= %s
          AND checked_at < %s
        ORDER BY checked_at ASC
        """,
        (
            site_id,
            slot_start.strftime("%Y-%m-%d %H:%M:%S"),
            slot_end.strftime("%Y-%m-%d %H:%M:%S"),
        ),
    )

    checkpoints: list[tuple[datetime, str]] = []
    for row in rows:
        checked_at = _parse_dt(row.get("checked_at"))
        if checked_at is None:
            continue
        if checked_at < slot_start or checked_at > slot_end:
            continue
        checkpoints.append((checked_at, _status_bucket(row.get("status"))))

    totals = {
        "online": 0,
        "offline": 0,
        "degraded": 0,
        "maintenance": 0,
        "unknown": 0,
    }

    cursor = slot_start
    current_bucket = previous_bucket
    for checked_at, bucket in checkpoints:
        if checked_at < cursor:
            current_bucket = bucket
            continue
        delta = int((checked_at - cursor).total_seconds())
        if delta > 0:
            totals[current_bucket] = totals.get(current_bucket, 0) + delta
        cursor = checked_at
        current_bucket = bucket

    remaining = int((slot_end - cursor).total_seconds())
    if remaining > 0:
        totals[current_bucket] = totals.get(current_bucket, 0) + remaining

    total_seconds = int((slot_end - slot_start).total_seconds())
    maintenance_seconds = int(totals.get("maintenance", 0))
    degraded_seconds = int(totals.get("degraded", 0))
    online_seconds = int(totals.get("online", 0))
    unknown_seconds = int(totals.get("unknown", 0))
    offline_seconds = int(totals.get("offline", 0))

    denominator = total_seconds - maintenance_seconds - unknown_seconds
    if denominator <= 0:
        availability_ratio = None
        health_score = None
    else:
        availability_ratio = round(max(0.0, min(1.0, (denominator - offline_seconds) / denominator)), 4)
        health_score = round(max(0.0, min(1.0, (online_seconds + (0.5 * degraded_seconds)) / denominator)), 4)

    return {
        "total_seconds": total_seconds,
        "offline_seconds": offline_seconds,
        "degraded_seconds": degraded_seconds,
        "maintenance_seconds": maintenance_seconds,
        "availability_ratio": availability_ratio,
        "health_score": health_score,
    }


def process_hourly(
    db: Database,
    site_id: int,
    date_value: str,
    hour_value: int,
    calc_method: str,
    *,
    probes_table_sql: str,
    hourly_table_sql: str,
) -> bool:
    minute_rows = db.query_all(
        f"""
        SELECT MINUTE(checked_at) AS minute, status
        FROM {probes_table_sql}
        WHERE site_id = %s AND DATE(checked_at) = %s AND HOUR(checked_at) = %s
        ORDER BY MINUTE(checked_at) ASC
        """,
        (site_id, date_value, hour_value),
    )
    minute_statuses = [0] * 60
    for row in minute_rows:
        minute = int(row.get("minute") or 0)
        if 0 <= minute < 60:
            minute_statuses[minute] = 1 if str(row.get("status")) == "online" else 0

    binary_sequence = _build_binary_sequence(minute_statuses)
    minutes_offline = binary_sequence.count("0")

    avg_row = db.query_one(
        f"""
        SELECT AVG(response_time) AS avg_response_time
        FROM {probes_table_sql}
        WHERE site_id = %s AND DATE(checked_at) = %s AND HOUR(checked_at) = %s
        """,
        (site_id, date_value, hour_value),
    )
    avg_response_time = float(avg_row.get("avg_response_time") or 0.0) if avg_row else 0.0

    slot_start = datetime.strptime(
        date_value + " " + str(hour_value).zfill(2) + ":00:00",
        "%Y-%m-%d %H:%M:%S",
    )
    slot_end = slot_start + timedelta(hours=1)

    weighted = _compute_weighted_hour_metrics(
        db,
        site_id,
        slot_start,
        slot_end,
        probes_table_sql=probes_table_sql,
    )

    minutes_offline_legacy = int(minutes_offline)
    minutes_offline_weighted = int(round(float(weighted["offline_seconds"]) / 60.0))
    if minutes_offline_weighted < 0:
        minutes_offline_weighted = 0
    if minutes_offline_weighted > 60:
        minutes_offline_weighted = 60

    if calc_method == "time_weighted":
        stored_minutes_offline = minutes_offline_weighted
        total_seconds = int(weighted["total_seconds"])
        offline_seconds = int(weighted["offline_seconds"])
        degraded_seconds = int(weighted["degraded_seconds"])
        maintenance_seconds = int(weighted["maintenance_seconds"])
        availability_ratio = weighted["availability_ratio"]
        health_score = weighted["health_score"]
    else:
        stored_minutes_offline = minutes_offline_legacy
        total_seconds = 3600
        maintenance_seconds = 0
        degraded_seconds = 0
        offline_seconds = max(0, min(3600, minutes_offline_legacy * 60))
        availability_ratio = round(max(0.0, min(1.0, (3600 - offline_seconds) / 3600)), 4)
        health_score = availability_ratio

    db.execute(
        f"""
        INSERT INTO {hourly_table_sql}
            (site_id, date, hour, avg_response_time, minutes_offline, binary_sequence, total_seconds, offline_seconds, degraded_seconds, maintenance_seconds, availability_ratio, health_score, calc_method, checked_at)
        VALUES (%s, %s, %s, %s, %s, %s, %s, %s, %s, %s, %s, %s, %s, NOW())
        ON DUPLICATE KEY UPDATE
            avg_response_time = VALUES(avg_response_time),
            minutes_offline = VALUES(minutes_offline),
            binary_sequence = VALUES(binary_sequence),
            total_seconds = VALUES(total_seconds),
            offline_seconds = VALUES(offline_seconds),
            degraded_seconds = VALUES(degraded_seconds),
            maintenance_seconds = VALUES(maintenance_seconds),
            availability_ratio = VALUES(availability_ratio),
            health_score = VALUES(health_score),
            calc_method = VALUES(calc_method),
            checked_at = NOW()
        """,
        (
            site_id,
            date_value,
            hour_value,
            avg_response_time,
            stored_minutes_offline,
            binary_sequence,
            total_seconds,
            offline_seconds,
            degraded_seconds,
            maintenance_seconds,
            availability_ratio,
            health_score,
            calc_method,
        ),
    )
    return True


def run_hourly_job(db: Database, cfg: dict | None = None) -> dict:
    cfg = cfg or {}
    probes_table = table_name("probes", cfg)
    hourly_table = table_name("hourly_stats", cfg)
    probes_table_sql = table_sql("probes", cfg)
    hourly_table_sql = table_sql("hourly_stats", cfg)
    _ensure_sites_runtime_columns(db)
    _ensure_hourly_stats_calc_columns(db, hourly_table)
    calc_settings = _ensure_calc_settings_table(db)
    switch_at = calc_settings.get("switch_at")
    default_calc_method = str(calc_settings.get("default_calc_method") or "time_weighted")

    processed = 0
    bad_data = 0
    sites = db.query_all("SELECT id, calc_method FROM sites")

    for site in sites:
        site_id = int(site.get("id") or 0)
        if site_id <= 0:
            continue
        site_calc_method = str(site.get("calc_method") or "inherit")

        rows = db.query_all(
            f"""
            SELECT DISTINCT DATE(checked_at) AS date, HOUR(checked_at) AS hour
            FROM {probes_table_sql}
            WHERE site_id = %s
              AND (
                DATE(checked_at) < CURDATE()
                OR (DATE(checked_at) = CURDATE() AND HOUR(checked_at) < HOUR(NOW()))
              )
            ORDER BY DATE(checked_at), HOUR(checked_at)
            """,
            (site_id,),
        )
        for row in rows:
            date_value = str(row.get("date") or "")
            hour_value = int(row.get("hour") or 0)
            if not date_value:
                bad_data += 1
                continue
            try:
                slot_start = datetime.strptime(
                    date_value + " " + str(hour_value).zfill(2) + ":00:00",
                    "%Y-%m-%d %H:%M:%S",
                )
                effective_method = _effective_calc_method(
                    site_calc_method,
                    slot_start,
                    switch_at if isinstance(switch_at, datetime) else None,
                    default_calc_method,
                )
                process_hourly(
                    db,
                    site_id,
                    date_value,
                    hour_value,
                    effective_method,
                    probes_table_sql=probes_table_sql,
                    hourly_table_sql=hourly_table_sql,
                )
                processed += 1
            except Exception:
                bad_data += 1

    switch_at_value = switch_at.strftime("%Y-%m-%d %H:%M:%S") if isinstance(switch_at, datetime) else None
    return {
        "ok": True,
        "processed": processed,
        "bad_data": bad_data,
        "tables": {"probes": probes_table, "hourly_stats": hourly_table},
        "calc_settings": {
            "switch_at": switch_at_value,
            "default_calc_method": default_calc_method,
        },
    }
