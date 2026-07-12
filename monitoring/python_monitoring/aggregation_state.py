from __future__ import annotations

from datetime import datetime, timedelta

from .db import Database


def ensure_aggregation_state_table(db: Database) -> None:
    db.execute(
        """
        CREATE TABLE IF NOT EXISTS monitoring_aggregation_state (
            job_name VARCHAR(64) NOT NULL,
            last_success_at DATETIME NOT NULL,
            updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (job_name)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
        """
    )


def aggregation_job_name(base: str, cfg: dict | None = None) -> str:
    suffix = str((cfg or {}).get("table_suffix", "") or "").strip()
    return (base + suffix)[:64]


def parse_database_datetime(value: object) -> datetime | None:
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


def database_now(db: Database) -> datetime:
    row = db.query_one("SELECT NOW() AS db_now") or {}
    return parse_database_datetime(row.get("db_now")) or datetime.now()


def aggregation_last_success(db: Database, job_name: str) -> datetime | None:
    ensure_aggregation_state_table(db)
    row = db.query_one(
        "SELECT last_success_at FROM monitoring_aggregation_state WHERE job_name = %s LIMIT 1",
        (job_name,),
    )
    return parse_database_datetime((row or {}).get("last_success_at"))


def aggregation_cutoff(
    db: Database,
    job_name: str,
    minimum_lookback: timedelta,
    overlap: timedelta,
) -> datetime | None:
    last_success = aggregation_last_success(db, job_name)
    if last_success is None:
        return None
    now = database_now(db)
    return min(last_success - overlap, now - minimum_lookback)


def mark_aggregation_success(db: Database, job_name: str) -> None:
    ensure_aggregation_state_table(db)
    db.execute(
        """
        INSERT INTO monitoring_aggregation_state (job_name, last_success_at)
        VALUES (%s, NOW())
        ON DUPLICATE KEY UPDATE last_success_at = VALUES(last_success_at)
        """,
        (job_name,),
    )
