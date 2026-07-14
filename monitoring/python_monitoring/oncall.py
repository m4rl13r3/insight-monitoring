from __future__ import annotations

from datetime import datetime, timedelta, timezone
from typing import Any
from urllib.parse import urlparse
from zoneinfo import ZoneInfo, ZoneInfoNotFoundError

from .db import Database
from .notifications import dispatch_event


SEVERITY_ORDER = {"info": 0, "minor": 1, "major": 2, "critical": 3}


def _integer(value: Any, default: int, minimum: int, maximum: int) -> int:
    try:
        parsed = int(str(value).strip())
    except Exception:
        parsed = default
    return max(minimum, min(maximum, parsed))


def _datetime(value: Any) -> datetime | None:
    if isinstance(value, datetime):
        return value.replace(tzinfo=None)
    text = str(value or "").strip().replace("T", " ")
    if not text:
        return None
    try:
        return datetime.fromisoformat(text).replace(tzinfo=None)
    except ValueError:
        return None


def _zone(value: Any, fallback: str = "UTC") -> ZoneInfo:
    try:
        return ZoneInfo(str(value or fallback).strip() or fallback)
    except ZoneInfoNotFoundError:
        return ZoneInfo(fallback)


def _active_window(shift: dict[str, Any], now_utc: datetime, zone: ZoneInfo) -> datetime | None:
    starts_at = _datetime(shift.get("starts_at"))
    ends_at = _datetime(shift.get("ends_at"))
    if starts_at is None or ends_at is None or ends_at <= starts_at:
        return None
    now = now_utc.astimezone(zone).replace(tzinfo=None)
    recurrence = str(shift.get("recurrence") or "none").strip().lower()
    if recurrence == "none":
        return starts_at if starts_at <= now < ends_at else None
    duration = ends_at - starts_at
    if recurrence == "daily":
        candidate = datetime.combine(now.date(), starts_at.time())
        if candidate > now:
            candidate -= timedelta(days=1)
        if candidate < starts_at:
            return None
        return candidate if candidate <= now < candidate + duration else None
    if recurrence == "weekly":
        day_offset = (now.weekday() - starts_at.weekday()) % 7
        candidate = datetime.combine(now.date() - timedelta(days=day_offset), starts_at.time())
        if candidate > now:
            candidate -= timedelta(weeks=1)
        if candidate < starts_at:
            return None
        return candidate if candidate <= now < candidate + duration else None
    return None


def _active_member(db: Database, schedule: dict[str, Any], now_utc: datetime) -> dict[str, Any] | None:
    rows = db.query_all(
        """
        SELECT m.id AS member_id, m.name AS member_name, m.channel_id,
               sh.id AS shift_id, sh.starts_at, sh.ends_at, sh.recurrence
        FROM oncall_members m
        INNER JOIN oncall_shifts sh ON sh.member_id = m.id AND sh.schedule_id = m.schedule_id
        INNER JOIN notification_channels channel ON channel.id = m.channel_id AND channel.enabled = 1
        WHERE m.schedule_id = %s AND m.active = 1
        ORDER BY m.sort_order, m.id, sh.starts_at
        """,
        (int(schedule["id"]),),
    )
    zone = _zone(schedule.get("timezone"))
    active = []
    for row in rows:
        window = _active_window(row, now_utc, zone)
        if window is not None:
            active.append((window, int(row.get("member_id") or 0), row))
    if not active:
        return None
    active.sort(key=lambda item: (item[0], -item[1]), reverse=True)
    return dict(active[0][2])


def _elapsed_seconds(value: Any, now_utc: datetime, configured_zone: ZoneInfo) -> int:
    started = _datetime(value)
    if started is None:
        return 0
    started_utc = started.replace(tzinfo=configured_zone).astimezone(timezone.utc)
    return max(0, int((now_utc - started_utc).total_seconds()))


def _sequence(schedule: dict[str, Any], elapsed_seconds: int) -> int:
    delay_seconds = _integer(schedule.get("escalation_delay_minutes"), 5, 0, 10080) * 60
    repeat_seconds = _integer(schedule.get("repeat_interval_minutes"), 15, 1, 10080) * 60
    maximum = _integer(schedule.get("maximum_repeats"), 3, 1, 100)
    if elapsed_seconds < delay_seconds:
        return 0
    return min(maximum, 1 + ((elapsed_seconds - delay_seconds) // repeat_seconds))


def apply_oncall_escalations(db: Database, cfg: dict[str, Any]) -> dict[str, int]:
    stats = {"due": 0, "sent": 0, "failed": 0, "without_shift": 0, "disabled": 0}
    now_utc = datetime.now(timezone.utc)
    configured_zone = _zone(cfg.get("timezone"), "UTC")
    incidents = db.query_all(
        """
        SELECT i.id, i.site_id, i.title, i.summary, i.severity, i.started_at, s.url AS site_url,
               GROUP_CONCAT(DISTINCT mapping.site_id ORDER BY mapping.site_id SEPARATOR ',') AS site_ids_csv
        FROM incidents i
        LEFT JOIN sites s ON s.id = i.site_id
        LEFT JOIN incident_sites mapping ON mapping.incident_id = i.id
        WHERE i.status = 0
          AND i.lifecycle_status IN ('started', 'monitoring')
          AND i.started_at IS NOT NULL
        GROUP BY i.id
        ORDER BY i.started_at, i.id
        """
    )
    for incident in incidents:
        incident_id = int(incident.get("id") or 0)
        site_id = int(incident.get("site_id") or 0)
        site_ids = {int(value) for value in str(incident.get("site_ids_csv") or "").split(",") if value.isdigit() and int(value) > 0}
        if site_id > 0:
            site_ids.add(site_id)
        incident_severity = str(incident.get("severity") or "major").strip().lower()
        elapsed = _elapsed_seconds(incident.get("started_at"), now_utc, configured_zone)
        routed_placeholders = ",".join(["%s"] * max(1, len(site_ids)))
        routed_params = tuple(sorted(site_ids)) if site_ids else (0,)
        schedules = db.query_all(
            f"""
            SELECT schedule.*
            FROM oncall_schedules schedule
            WHERE schedule.enabled = 1
              AND (
                  NOT EXISTS (
                      SELECT 1 FROM oncall_schedule_sites configured
                      WHERE configured.schedule_id = schedule.id
                  )
                  OR EXISTS (
                      SELECT 1 FROM oncall_schedule_sites routed
                      WHERE routed.schedule_id = schedule.id AND routed.site_id IN ({routed_placeholders})
                  )
              )
            ORDER BY schedule.id
            """,
            routed_params,
        )
        for schedule in schedules:
            minimum = str(schedule.get("minimum_severity") or "major").strip().lower()
            if SEVERITY_ORDER.get(incident_severity, 2) < SEVERITY_ORDER.get(minimum, 2):
                continue
            sequence = _sequence(schedule, elapsed)
            if sequence <= 0:
                continue
            member = _active_member(db, schedule, now_utc)
            if member is None:
                stats["without_shift"] += 1
                continue
            schedule_id = int(schedule.get("id") or 0)
            member_id = int(member.get("member_id") or 0)
            channel_id = int(member.get("channel_id") or 0)
            existing = db.query_one(
                """
                SELECT id, status, attempts, last_attempt_at
                FROM oncall_escalation_events
                WHERE incident_id = %s AND schedule_id = %s AND member_id = %s AND sequence_no = %s
                LIMIT 1
                """,
                (incident_id, schedule_id, member_id, sequence),
            )
            if existing is not None and str(existing.get("status")) == "sent":
                continue
            attempts = int(existing.get("attempts") or 0) if existing else 0
            last_attempt = _datetime(existing.get("last_attempt_at")) if existing else None
            if attempts >= 3 or (last_attempt is not None and (datetime.now() - last_attempt).total_seconds() < 60):
                continue
            stats["due"] += 1
            site_url = str(incident.get("site_url") or incident.get("title") or "service")
            host = urlparse(site_url if "://" in site_url else f"https://{site_url}").hostname or site_url
            message = (
                f"On-call escalation {sequence}/{int(schedule.get('maximum_repeats') or 1)} for "
                f"{str(member.get('member_name') or 'on-call')}: incident unacknowledged for {elapsed // 60} minutes."
            )
            result = dispatch_event(
                db,
                cfg,
                "incident_open",
                {
                    "app_name": str(cfg.get("app_name") or "Insight"),
                    "public_url": str(cfg.get("public_url") or ""),
                    "id": incident_id,
                    "incident_id": incident_id,
                    "site_id": site_id,
                    "site_ids": sorted(site_ids),
                    "site_url": site_url,
                    "sites": site_url,
                    "domain": host,
                    "severity": incident_severity,
                    "status": "offline",
                    "message": message,
                    "notify_subscribers": False,
                    "escalation_sequence": sequence,
                    "oncall_member": str(member.get("member_name") or ""),
                    "oncall_schedule": str(schedule.get("name") or ""),
                },
                channel_ids=[channel_id],
                idempotency_key=f"oncall:{incident_id}:{schedule_id}:{member_id}:{sequence}:{attempts + 1}",
            )
            if result.get("disabled"):
                stats["disabled"] += 1
                continue
            delivered = int(result.get("sent") or 0) > 0 and int(result.get("failed") or 0) == 0
            error = "" if delivered else "No eligible channel accepted the escalation."
            db.execute(
                """
                INSERT INTO oncall_escalation_events
                    (incident_id, schedule_id, member_id, sequence_no, status, attempts, last_error, last_attempt_at, delivered_at)
                VALUES (%s, %s, %s, %s, %s, 1, %s, CURRENT_TIMESTAMP, %s)
                ON DUPLICATE KEY UPDATE
                    status = VALUES(status), attempts = attempts + 1, last_error = VALUES(last_error),
                    last_attempt_at = CURRENT_TIMESTAMP, delivered_at = VALUES(delivered_at)
                """,
                (
                    incident_id,
                    schedule_id,
                    member_id,
                    sequence,
                    "sent" if delivered else "failed",
                    error or None,
                    datetime.now() if delivered else None,
                ),
            )
            stats["sent" if delivered else "failed"] += 1
    return stats
