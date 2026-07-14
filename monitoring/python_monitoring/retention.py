from __future__ import annotations

from datetime import timedelta
from pathlib import Path

from .aggregation_state import aggregation_job_name, aggregation_last_success, database_now
from .db import Database
from .distributed import cleanup as cleanup_distributed
from .table_names import table_sql


def _bounded_int(value: object, default: int, minimum: int, maximum: int) -> int:
    try:
        parsed = int(str(value).strip())
    except Exception:
        parsed = default
    return max(minimum, min(maximum, parsed))


def _delete_in_batches(db: Database, table: str, condition: str, batch_size: int) -> int:
    deleted = 0
    while True:
        count = db.execute(f"DELETE FROM {table} WHERE {condition} LIMIT {batch_size}")
        deleted += max(0, count)
        if count < batch_size:
            return deleted


def _delete_diagnostics(db: Database, data_dir: str, days: int, batch_size: int) -> tuple[int, int]:
    root = Path(data_dir or "/var/lib/insight").resolve()
    deleted = 0
    deleted_files = 0
    while True:
        rows = db.query_all(
            f"SELECT id, artifact_path FROM probe_diagnostics WHERE created_at < DATE_SUB(NOW(), INTERVAL {days} DAY) ORDER BY id LIMIT {batch_size}"
        )
        if not rows:
            return deleted, deleted_files
        ids: list[int] = []
        for row in rows:
            diagnostic_id = int(row.get("id") or 0)
            if diagnostic_id > 0:
                ids.append(diagnostic_id)
            relative = str(row.get("artifact_path") or "").strip()
            if not relative:
                continue
            candidate = (root / relative).resolve()
            try:
                candidate.relative_to(root)
            except ValueError:
                continue
            if candidate.is_file():
                candidate.unlink()
                deleted_files += 1
        if ids:
            deleted += max(
                0,
                db.execute(
                    "DELETE FROM probe_diagnostics WHERE id IN (" + ",".join(["%s"] * len(ids)) + ")",
                    tuple(ids),
                ),
            )
        if len(rows) < batch_size:
            return deleted, deleted_files


def run_retention_job(db: Database, cfg: dict | None = None) -> dict:
    cfg = cfg or {}
    probe_days = _bounded_int(cfg.get("probe_retention_days"), 30, 2, 3650)
    diagnostic_days = _bounded_int(cfg.get("diagnostic_retention_days"), 14, 1, 3650)
    hourly_days = _bounded_int(cfg.get("hourly_retention_days"), 365, 7, 3650)
    daily_days = _bounded_int(cfg.get("daily_retention_days"), 730, 30, 36500)
    tls_days = _bounded_int(cfg.get("tls_retention_days"), 365, 7, 3650)
    batch_size = _bounded_int(cfg.get("retention_batch_size"), 5000, 100, 50000)

    now = database_now(db)
    hourly_success = aggregation_last_success(db, aggregation_job_name("hourly", cfg))
    daily_success = aggregation_last_success(db, aggregation_job_name("daily", cfg))
    hourly_fresh = hourly_success is not None and now - hourly_success <= timedelta(hours=24)
    daily_fresh = daily_success is not None and now - daily_success <= timedelta(hours=48)

    deleted: dict[str, int] = {}
    skipped: list[str] = []

    if hourly_fresh:
        deleted["probes"] = _delete_in_batches(
            db,
            table_sql("probes", cfg),
            f"checked_at < DATE_SUB(NOW(), INTERVAL {probe_days} DAY)",
            batch_size,
        )
    else:
        skipped.append("probes:hourly_not_fresh")

    if daily_fresh:
        deleted["hourly_stats"] = _delete_in_batches(
            db,
            table_sql("hourly_stats", cfg),
            f"date < DATE_SUB(CURDATE(), INTERVAL {hourly_days} DAY)",
            batch_size,
        )
    else:
        skipped.append("hourly_stats:daily_not_fresh")

    deleted["daily_stats"] = _delete_in_batches(
        db,
        table_sql("daily_stats", cfg),
        f"date < DATE_SUB(CURDATE(), INTERVAL {daily_days} DAY)",
        batch_size,
    )
    deleted["ssl_checks"] = _delete_in_batches(
        db,
        "`ssl_checks`",
        f"checked_at < DATE_SUB(NOW(), INTERVAL {tls_days} DAY)",
        batch_size,
    )
    diagnostics_deleted, diagnostic_files_deleted = _delete_diagnostics(
        db,
        str(cfg.get("data_dir") or "/var/lib/insight"),
        diagnostic_days,
        batch_size,
    )
    deleted["probe_diagnostics"] = diagnostics_deleted
    deleted["diagnostic_files"] = diagnostic_files_deleted
    deleted.update(cleanup_distributed(db))

    return {
        "ok": True,
        "deleted": deleted,
        "skipped": skipped,
        "settings": {
            "probe_retention_days": probe_days,
            "diagnostic_retention_days": diagnostic_days,
            "hourly_retention_days": hourly_days,
            "daily_retention_days": daily_days,
            "tls_retention_days": tls_days,
            "batch_size": batch_size,
        },
    }
