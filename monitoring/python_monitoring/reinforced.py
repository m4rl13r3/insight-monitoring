from __future__ import annotations

import os
from datetime import datetime, timedelta
from typing import Any

from .db import Database


def _bounded_int(value: Any, default: int, minimum: int, maximum: int) -> int:
    try:
        parsed = int(str(value).strip())
    except Exception:
        parsed = default
    return max(minimum, min(maximum, parsed))


def _truthy(value: Any, default: bool = False) -> bool:
    if value is None or str(value).strip() == "":
        return default
    return str(value).strip().lower() in {"1", "true", "yes", "on"}


def reinforced_settings(cfg: dict[str, Any] | None = None) -> dict[str, Any]:
    values = cfg or {}
    enabled = _truthy(
        values.get("reinforced_monitoring_enabled", os.getenv("INSIGHT_REINFORCED_MONITORING_ENABLED")),
        True,
    )
    duration_sec = _bounded_int(
        values.get("reinforced_monitoring_duration_sec", os.getenv("INSIGHT_REINFORCED_MONITORING_DURATION_SEC")),
        900,
        60,
        86400,
    )
    interval_sec = _bounded_int(
        values.get("reinforced_monitor_interval_sec", os.getenv("INSIGHT_REINFORCED_MONITOR_INTERVAL_SEC")),
        10,
        10,
        300,
    )
    return {
        "enabled": enabled,
        "duration_sec": duration_sec,
        "interval_sec": interval_sec,
    }


def watch_is_active(watch: dict[str, Any] | None, now: datetime | None = None) -> bool:
    if not watch:
        return False
    ends_at = watch.get("ends_at")
    if not isinstance(ends_at, datetime):
        try:
            ends_at = datetime.fromisoformat(str(ends_at or ""))
        except ValueError:
            return False
    return ends_at > (now or datetime.now())


def effective_interval(base_interval_sec: int, watch: dict[str, Any] | None) -> int:
    base = max(10, int(base_interval_sec or 60))
    if not watch_is_active(watch):
        return base
    reinforced = _bounded_int(watch.get("interval_sec"), 10, 10, 300)
    return min(base, reinforced)


def load_active_watches(db: Database, site_ids: list[int] | None = None) -> dict[int, dict[str, Any]]:
    params: tuple[Any, ...] = ()
    where = "ends_at > CURRENT_TIMESTAMP(3)"
    if site_ids is not None:
        normalized = sorted({int(site_id) for site_id in site_ids if int(site_id) > 0})
        if not normalized:
            return {}
        placeholders = ", ".join(["%s"] * len(normalized))
        where += f" AND site_id IN ({placeholders})"
        params = tuple(normalized)
    rows = db.query_all(
        f"""
        SELECT site_id, incident_id, source_mode, started_at, ends_at, interval_sec
        FROM monitoring_reinforced_watch
        WHERE {where}
        ORDER BY site_id
        """,
        params,
    )
    return {int(row["site_id"]): dict(row) for row in rows}


def activate_reinforced_watch(
    db: Database,
    site_id: int,
    incident_id: int | None,
    source_mode: str,
    cfg: dict[str, Any] | None = None,
) -> dict[str, Any]:
    settings = reinforced_settings(cfg)
    if not settings["enabled"] or site_id <= 0:
        return {"active": False, **settings}
    now = datetime.now()
    ends_at = now + timedelta(seconds=int(settings["duration_sec"]))
    db.execute(
        """
        INSERT INTO monitoring_reinforced_watch
            (site_id, incident_id, source_mode, started_at, ends_at, interval_sec)
        VALUES (%s, %s, %s, %s, %s, %s)
        ON DUPLICATE KEY UPDATE
            incident_id = VALUES(incident_id),
            source_mode = VALUES(source_mode),
            started_at = VALUES(started_at),
            ends_at = GREATEST(ends_at, VALUES(ends_at)),
            interval_sec = VALUES(interval_sec),
            updated_at = CURRENT_TIMESTAMP(3)
        """,
        (site_id, incident_id, source_mode[:16], now, ends_at, int(settings["interval_sec"])),
    )
    return {
        "active": True,
        "site_id": site_id,
        "incident_id": incident_id,
        "source_mode": source_mode[:16],
        "started_at": now,
        "ends_at": ends_at,
        **settings,
    }
