from __future__ import annotations

import sys
import unittest
from datetime import datetime, timedelta, timezone
from pathlib import Path
from unittest.mock import patch
from zoneinfo import ZoneInfo


ROOT = Path(__file__).resolve().parents[1]
sys.path.insert(0, str(ROOT / "monitoring"))

from python_monitoring.oncall import _active_window, _sequence, apply_oncall_escalations


class OnCallDatabase:
    def __init__(self) -> None:
        now = datetime.now(timezone.utc).replace(tzinfo=None)
        self.incidents = [
            {
                "id": 81,
                "site_id": 42,
                "title": "API unavailable",
                "summary": "The API is unavailable.",
                "severity": "critical",
                "started_at": now - timedelta(minutes=10),
                "site_url": "https://api.example.test",
                "site_ids_csv": "42,43",
            }
        ]
        self.schedules = [
            {
                "id": 9,
                "name": "Primary rotation",
                "timezone": "UTC",
                "escalation_delay_minutes": 0,
                "repeat_interval_minutes": 15,
                "maximum_repeats": 3,
                "minimum_severity": "major",
            }
        ]
        self.members = [
            {
                "member_id": 7,
                "member_name": "Primary operator",
                "channel_id": 5,
                "shift_id": 3,
                "starts_at": now - timedelta(hours=1),
                "ends_at": now + timedelta(hours=1),
                "recurrence": "none",
            }
        ]
        self.existing = None
        self.executed: list[tuple[str, tuple]] = []
        self.schedule_params: tuple = ()

    def query_all(self, query: str, params: tuple = ()) -> list[dict]:
        if "FROM incidents" in query:
            return self.incidents
        if "FROM oncall_schedules" in query:
            self.schedule_params = params
            return self.schedules
        if "FROM oncall_members" in query:
            return self.members
        raise AssertionError(query)

    def query_one(self, query: str, _params: tuple = ()) -> dict | None:
        if "FROM oncall_escalation_events" in query:
            return self.existing
        raise AssertionError(query)

    def execute(self, query: str, params: tuple = ()) -> int:
        self.executed.append((query, params))
        return 1


class OnCallTests(unittest.TestCase):
    def test_one_time_shift_is_active_inside_window(self) -> None:
        shift = {"starts_at": "2026-07-13 08:00:00", "ends_at": "2026-07-13 18:00:00", "recurrence": "none"}
        now = datetime(2026, 7, 13, 10, 0, tzinfo=timezone.utc)
        self.assertEqual(datetime(2026, 7, 13, 8, 0), _active_window(shift, now, ZoneInfo("UTC")))

    def test_daily_shift_can_cross_midnight(self) -> None:
        shift = {"starts_at": "2026-07-01 22:00:00", "ends_at": "2026-07-02 06:00:00", "recurrence": "daily"}
        now = datetime(2026, 7, 13, 2, 0, tzinfo=timezone.utc)
        self.assertEqual(datetime(2026, 7, 12, 22, 0), _active_window(shift, now, ZoneInfo("UTC")))

    def test_weekly_shift_uses_schedule_timezone(self) -> None:
        shift = {"starts_at": "2026-07-06 09:00:00", "ends_at": "2026-07-06 17:00:00", "recurrence": "weekly"}
        now = datetime(2026, 7, 13, 8, 30, tzinfo=timezone.utc)
        self.assertEqual(datetime(2026, 7, 13, 9, 0), _active_window(shift, now, ZoneInfo("Europe/Paris")))

    def test_escalation_starts_after_delay(self) -> None:
        schedule = {"escalation_delay_minutes": 5, "repeat_interval_minutes": 15, "maximum_repeats": 3}
        self.assertEqual(0, _sequence(schedule, 299))
        self.assertEqual(1, _sequence(schedule, 300))
        self.assertEqual(2, _sequence(schedule, 1200))

    def test_escalation_sequence_is_bounded(self) -> None:
        schedule = {"escalation_delay_minutes": 0, "repeat_interval_minutes": 1, "maximum_repeats": 3}
        self.assertEqual(3, _sequence(schedule, 86400))

    def test_due_escalation_routes_all_incident_sites_and_persists_delivery(self) -> None:
        database = OnCallDatabase()
        with patch("python_monitoring.oncall.dispatch_event", return_value={"sent": 1, "failed": 0}) as dispatch:
            result = apply_oncall_escalations(database, {"timezone": "UTC", "app_name": "Insight"})
        self.assertEqual({"due": 1, "sent": 1, "failed": 0, "without_shift": 0, "disabled": 0}, result)
        self.assertEqual((42, 43), database.schedule_params)
        self.assertEqual([42, 43], dispatch.call_args.args[3]["site_ids"])
        self.assertEqual([5], dispatch.call_args.kwargs["channel_ids"])
        self.assertTrue(any("INSERT INTO oncall_escalation_events" in query for query, _ in database.executed))

    def test_sent_escalation_is_idempotent(self) -> None:
        database = OnCallDatabase()
        database.existing = {"id": 2, "status": "sent", "attempts": 1, "last_attempt_at": datetime.now()}
        with patch("python_monitoring.oncall.dispatch_event") as dispatch:
            result = apply_oncall_escalations(database, {"timezone": "UTC"})
        self.assertEqual(0, result["due"])
        dispatch.assert_not_called()

    def test_acknowledged_incident_is_not_escalated(self) -> None:
        database = OnCallDatabase()
        database.incidents = []
        with patch("python_monitoring.oncall.dispatch_event") as dispatch:
            result = apply_oncall_escalations(database, {"timezone": "UTC"})
        self.assertEqual(0, result["due"])
        dispatch.assert_not_called()

    def test_minimum_severity_is_enforced(self) -> None:
        database = OnCallDatabase()
        database.incidents[0]["severity"] = "minor"
        with patch("python_monitoring.oncall.dispatch_event") as dispatch:
            result = apply_oncall_escalations(database, {"timezone": "UTC"})
        self.assertEqual(0, result["due"])
        dispatch.assert_not_called()


if __name__ == "__main__":
    unittest.main()
