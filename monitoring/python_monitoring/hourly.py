from __future__ import annotations

import json
from datetime import datetime, timedelta

from .aggregation_state import aggregation_cutoff, aggregation_job_name, mark_aggregation_success
from .db import Database
from .table_names import table_name, table_sql


SUPPORTED_CALC_METHODS = {"inherit", "legacy", "time_weighted", "sample_ratio", "interval_capped", "strict_sla"}


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
            default_calc_method VARCHAR(24) NOT NULL DEFAULT 'interval_capped',
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
        default_method = str(row.get("default_calc_method") or "interval_capped").strip().lower()
        if default_method not in SUPPORTED_CALC_METHODS - {"inherit"}:
            default_method = "interval_capped"
        return {
            "switch_at": switch_dt,
            "default_calc_method": default_method,
        }

    switch_dt = (datetime.now().replace(hour=0, minute=0, second=0, microsecond=0) + timedelta(days=1))
    switch_value = switch_dt.strftime("%Y-%m-%d %H:%M:%S")
    db.execute(
        """
        INSERT INTO monitoring_calc_settings (singleton_id, switch_at, default_calc_method)
        VALUES (1, %s, 'interval_capped')
        ON DUPLICATE KEY UPDATE switch_at = switch_at
        """,
        (switch_value,),
    )
    return {
        "switch_at": switch_dt,
        "default_calc_method": "interval_capped",
    }


def _effective_calc_method(site_calc_method: str, slot_start: datetime, switch_at: datetime | None, default_calc_method: str) -> str:
    site_method = _normalize_calc_method(site_calc_method)
    if site_method != "inherit":
        return site_method
    default_method = str(default_calc_method or "interval_capped").strip().lower()
    if default_method not in SUPPORTED_CALC_METHODS - {"inherit"}:
        default_method = "interval_capped"
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
    validity_cap_sec: int | None = None,
) -> dict:
    prev = db.query_one(
        f"""
        SELECT checked_at, status
        FROM {probes_table_sql}
        WHERE site_id = %s
          AND checked_at < %s
        ORDER BY checked_at DESC
        LIMIT 1
        """,
        (site_id, slot_start.strftime("%Y-%m-%d %H:%M:%S")),
    )
    previous_bucket = _status_bucket(prev.get("status")) if prev else "unknown"
    previous_checked_at = _parse_dt(prev.get("checked_at")) if prev else None

    rows = db.query_all(
        f"""
        SELECT checked_at, status, response_time
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
    minute_statuses = [0] * 60
    response_time_sum = 0.0
    sample_count = 0
    sample_status_counts = {"online": 0, "offline": 0, "degraded": 0, "maintenance": 0, "unknown": 0}
    for row in rows:
        checked_at = _parse_dt(row.get("checked_at"))
        if checked_at is None:
            continue
        if checked_at < slot_start or checked_at > slot_end:
            continue
        bucket = _status_bucket(row.get("status"))
        checkpoints.append((checked_at, bucket))
        sample_status_counts[bucket] = sample_status_counts.get(bucket, 0) + 1
        if 0 <= checked_at.minute < 60:
            minute_statuses[checked_at.minute] = 1 if bucket == "online" else 0
        response_time = row.get("response_time")
        if response_time is not None:
            try:
                response_time_sum += float(response_time)
                sample_count += 1
            except (TypeError, ValueError):
                pass

    totals = {
        "online": 0,
        "offline": 0,
        "degraded": 0,
        "maintenance": 0,
        "unknown": 0,
    }

    cursor = slot_start
    current_bucket = previous_bucket
    valid_until = previous_checked_at + timedelta(seconds=validity_cap_sec) if validity_cap_sec is not None and previous_checked_at is not None else None

    def accumulate(until: datetime) -> None:
        nonlocal cursor, current_bucket, valid_until
        if until <= cursor:
            return
        if validity_cap_sec is not None and valid_until is not None and valid_until <= cursor:
            current_bucket = "unknown"
            valid_until = None
        if validity_cap_sec is not None and valid_until is not None and valid_until < until:
            active_delta = max(0, int((valid_until - cursor).total_seconds()))
            if active_delta > 0:
                totals[current_bucket] = totals.get(current_bucket, 0) + active_delta
            unknown_delta = max(0, int((until - valid_until).total_seconds()))
            totals["unknown"] += unknown_delta
            cursor = until
            current_bucket = "unknown"
            valid_until = None
            return
        delta = max(0, int((until - cursor).total_seconds()))
        if delta > 0:
            totals[current_bucket] = totals.get(current_bucket, 0) + delta
        cursor = until

    for checked_at, bucket in checkpoints:
        if checked_at < cursor:
            current_bucket = bucket
            valid_until = checked_at + timedelta(seconds=validity_cap_sec) if validity_cap_sec is not None else None
            continue
        accumulate(checked_at)
        current_bucket = bucket
        valid_until = checked_at + timedelta(seconds=validity_cap_sec) if validity_cap_sec is not None else None

    accumulate(slot_end)

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
        "unknown_seconds": unknown_seconds,
        "binary_sequence": _build_binary_sequence(minute_statuses),
        "sample_count": sample_count,
        "observation_count": sum(sample_status_counts.values()),
        "sample_status_counts": sample_status_counts,
        "response_time_sum": round(response_time_sum, 3),
        "availability_ratio": availability_ratio,
        "health_score": health_score,
    }


def process_hourly(
    db: Database,
    site_id: int,
    date_value: str,
    hour_value: int,
    calc_method: str,
    probe_interval_sec: int = 60,
    *,
    probes_table_sql: str,
    hourly_table_sql: str,
) -> bool:
    slot_start = datetime.strptime(
        date_value + " " + str(hour_value).zfill(2) + ":00:00",
        "%Y-%m-%d %H:%M:%S",
    )
    slot_end = slot_start + timedelta(hours=1)

    interval_sec = max(10, min(86400, int(probe_interval_sec or 60)))
    validity_cap_sec = max(30, min(3600, interval_sec * 2)) if calc_method == "interval_capped" else None
    weighted = _compute_weighted_hour_metrics(
        db,
        site_id,
        slot_start,
        slot_end,
        probes_table_sql=probes_table_sql,
        validity_cap_sec=validity_cap_sec,
    )
    binary_sequence = str(weighted["binary_sequence"])
    minutes_offline = binary_sequence.count("0")
    sample_count = int(weighted["sample_count"])
    response_time_sum = float(weighted["response_time_sum"])
    avg_response_time = response_time_sum / sample_count if sample_count > 0 else 0.0

    minutes_offline_legacy = int(minutes_offline)
    minutes_offline_weighted = int(round(float(weighted["offline_seconds"]) / 60.0))
    if minutes_offline_weighted < 0:
        minutes_offline_weighted = 0
    if minutes_offline_weighted > 60:
        minutes_offline_weighted = 60

    total_seconds = int(weighted["total_seconds"])
    offline_seconds = int(weighted["offline_seconds"])
    degraded_seconds = int(weighted["degraded_seconds"])
    maintenance_seconds = int(weighted["maintenance_seconds"])
    unknown_seconds = int(weighted["unknown_seconds"])
    method_details: dict[str, object] = {"version": 1}

    if calc_method in {"time_weighted", "interval_capped"}:
        stored_minutes_offline = minutes_offline_weighted
        availability_ratio = weighted["availability_ratio"]
        health_score = weighted["health_score"]
        availability_basis_seconds = max(0, total_seconds - maintenance_seconds - unknown_seconds)
        if calc_method == "interval_capped":
            method_details["validity_cap_seconds"] = validity_cap_sec
    elif calc_method == "sample_ratio":
        counts = dict(weighted.get("sample_status_counts") or {})
        online_samples = int(counts.get("online") or 0)
        offline_samples = int(counts.get("offline") or 0)
        degraded_samples = int(counts.get("degraded") or 0)
        eligible_samples = online_samples + offline_samples + degraded_samples
        availability_basis_seconds = eligible_samples * interval_sec
        if eligible_samples > 0:
            availability_ratio = round((online_samples + degraded_samples) / eligible_samples, 4)
            health_score = round((online_samples + (0.5 * degraded_samples)) / eligible_samples, 4)
            stored_minutes_offline = int(round((1.0 - availability_ratio) * 60.0))
        else:
            availability_ratio = None
            health_score = None
            stored_minutes_offline = 0
        method_details.update({"eligible_samples": eligible_samples, "sample_interval_seconds": interval_sec})
    elif calc_method == "strict_sla":
        availability_basis_seconds = max(0, total_seconds - maintenance_seconds)
        unavailable_seconds = offline_seconds + degraded_seconds + unknown_seconds
        if availability_basis_seconds > 0:
            availability_ratio = round(max(0.0, min(1.0, (availability_basis_seconds - unavailable_seconds) / availability_basis_seconds)), 4)
            health_score = availability_ratio
            stored_minutes_offline = int(round(unavailable_seconds / 60.0))
        else:
            availability_ratio = None
            health_score = None
            stored_minutes_offline = 0
        method_details["unavailable_states"] = ["offline", "degraded", "unknown"]
    else:
        stored_minutes_offline = minutes_offline_legacy
        total_seconds = 3600
        maintenance_seconds = 0
        degraded_seconds = 0
        unknown_seconds = 0
        offline_seconds = max(0, min(3600, minutes_offline_legacy * 60))
        availability_ratio = round(max(0.0, min(1.0, (3600 - offline_seconds) / 3600)), 4)
        health_score = availability_ratio
        availability_basis_seconds = 3600
        method_details["compatibility_mode"] = True

    db.execute(
        f"""
        INSERT INTO {hourly_table_sql}
            (site_id, date, hour, avg_response_time, minutes_offline, binary_sequence, total_seconds, offline_seconds, degraded_seconds, maintenance_seconds, unknown_seconds, sample_count, response_time_sum, availability_ratio, availability_basis_seconds, health_score, calc_method, method_details, checked_at)
        VALUES (%s, %s, %s, %s, %s, %s, %s, %s, %s, %s, %s, %s, %s, %s, %s, %s, %s, %s, NOW())
        ON DUPLICATE KEY UPDATE
            avg_response_time = VALUES(avg_response_time),
            minutes_offline = VALUES(minutes_offline),
            binary_sequence = VALUES(binary_sequence),
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
            unknown_seconds,
            sample_count,
            response_time_sum,
            availability_ratio,
            availability_basis_seconds,
            health_score,
            calc_method,
            json.dumps(method_details, ensure_ascii=False, separators=(",", ":")),
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
    default_calc_method = str(calc_settings.get("default_calc_method") or "interval_capped")
    try:
        reprocess_hours = int(str(cfg.get("aggregation_reprocess_hours", "2")).strip())
    except Exception:
        reprocess_hours = 2
    reprocess_hours = max(2, min(24 * 30, reprocess_hours))
    job_name = aggregation_job_name("hourly", cfg)
    cutoff = aggregation_cutoff(
        db,
        job_name,
        timedelta(hours=reprocess_hours),
        timedelta(hours=1),
    )

    processed = 0
    bad_data = 0
    sites = db.query_all("SELECT id, calc_method, probe_interval_sec FROM sites")

    for site in sites:
        site_id = int(site.get("id") or 0)
        if site_id <= 0:
            continue
        site_calc_method = str(site.get("calc_method") or "inherit")

        cutoff_sql = ""
        params: tuple[object, ...] = (site_id,)
        if cutoff is not None:
            cutoff_sql = "AND checked_at >= %s"
            params = (site_id, cutoff.strftime("%Y-%m-%d %H:%M:%S"))
        rows = db.query_all(
            f"""
            SELECT DISTINCT DATE(checked_at) AS date, HOUR(checked_at) AS hour
            FROM {probes_table_sql}
            WHERE site_id = %s
              {cutoff_sql}
              AND (
                DATE(checked_at) < CURDATE()
                OR (DATE(checked_at) = CURDATE() AND HOUR(checked_at) < HOUR(NOW()))
              )
            ORDER BY DATE(checked_at), HOUR(checked_at)
            """,
            params,
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
                    int(site.get("probe_interval_sec") or 60),
                    probes_table_sql=probes_table_sql,
                    hourly_table_sql=hourly_table_sql,
                )
                processed += 1
            except Exception:
                bad_data += 1

    if bad_data == 0:
        mark_aggregation_success(db, job_name)

    switch_at_value = switch_at.strftime("%Y-%m-%d %H:%M:%S") if isinstance(switch_at, datetime) else None
    return {
        "ok": True,
        "processed": processed,
        "bad_data": bad_data,
        "tables": {"probes": probes_table, "hourly_stats": hourly_table},
        "reprocess_from": cutoff.strftime("%Y-%m-%d %H:%M:%S") if cutoff is not None else None,
        "calc_settings": {
            "switch_at": switch_at_value,
            "default_calc_method": default_calc_method,
        },
    }
