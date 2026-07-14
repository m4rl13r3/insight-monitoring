from __future__ import annotations

import os
from typing import Any

from .db import Database


def _apply(db: Database, values: dict[str, Any]) -> None:
    allowed = {
        "service_name",
        "service_timezone",
        "app_env",
        "active_engine",
        "monitor_last_ok",
        "monitor_last_message",
        "monitor_python_error",
        "monitor_checked_by",
        "sites_checked",
        "errors_count",
        "incidents_opened",
        "incidents_closed",
        "hourly_last_ok",
        "hourly_processed",
        "hourly_bad_data",
        "hourly_engine",
        "daily_last_ok",
        "daily_processed",
        "daily_bad_data",
        "daily_engine",
        "last_monitor_at",
        "last_hourly_at",
        "last_daily_at",
    }
    payload = {key: value for key, value in values.items() if key in allowed}
    db.execute("INSERT IGNORE INTO monitoring_public_runtime_state (singleton_id) VALUES (1)")
    if payload:
        assignments = ", ".join(f"`{key}` = %s" for key in payload)
        db.execute(
            f"UPDATE monitoring_public_runtime_state SET {assignments}, updated_at = CURRENT_TIMESTAMP WHERE singleton_id = 1",
            tuple(payload.values()),
        )
    db.execute(
        """
        UPDATE monitoring_public_runtime_state
        SET is_degraded = CASE
            WHEN last_monitor_at IS NULL OR monitor_last_ok = 0 THEN 1
            WHEN last_hourly_at IS NOT NULL AND hourly_last_ok = 0 THEN 1
            WHEN last_daily_at IS NOT NULL AND daily_last_ok = 0 THEN 1
            ELSE 0
        END
        WHERE singleton_id = 1
        """
    )


def write_monitor_state(
    db: Database,
    result: dict[str, Any] | None,
    error: str | None = None,
    engine: str = "python",
    checked_by: str = "python",
) -> None:
    data = result or {}
    success = error is None and bool(data.get("ok", True))
    _apply(
        db,
        {
            "service_name": "insight",
            "service_timezone": str(os.getenv("INSIGHT_TIMEZONE", "Europe/Paris")),
            "app_env": str(os.getenv("INSIGHT_APP_ENV", "production")),
            "active_engine": engine,
            "monitor_last_ok": 1 if success else 0,
            "monitor_last_message": f"{engine.capitalize()} monitoring is healthy." if success else f"{engine.capitalize()} monitoring failed.",
            "monitor_python_error": None if success else str(error or data.get("message") or "Unknown monitoring error."),
            "monitor_checked_by": checked_by,
            "sites_checked": int(data.get("sites_checked", data.get("evaluated", 0)) or 0),
            "errors_count": int(data.get("errors", 0) or 0),
            "incidents_opened": int(data.get("incidents_opened", 0) or 0),
            "incidents_closed": int(data.get("incidents_closed", 0) or 0),
            "last_monitor_at": _database_timestamp(db),
        },
    )


def write_aggregation_state(db: Database, job: str, result: dict[str, Any] | None, error: str | None = None) -> None:
    if job not in {"hourly", "daily"}:
        raise ValueError("Invalid aggregation job.")
    data = result or {}
    success = error is None and bool(data.get("ok", True))
    _apply(
        db,
        {
            f"{job}_last_ok": 1 if success else 0,
            f"{job}_processed": int(data.get("processed", 0) or 0),
            f"{job}_bad_data": int(data.get("bad_data", 0) or 0),
            f"{job}_engine": "python",
            f"last_{job}_at": _database_timestamp(db),
        },
    )


def _database_timestamp(db: Database) -> Any:
    row = db.query_one("SELECT NOW() AS db_now") or {}
    return row.get("db_now")
