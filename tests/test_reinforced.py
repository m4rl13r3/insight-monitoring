from __future__ import annotations

import os
import unittest
from datetime import datetime, timedelta
from unittest.mock import patch

from monitoring.python_monitoring.reinforced import (
    activate_reinforced_watch,
    effective_interval,
    load_active_watches,
    reinforced_settings,
    watch_is_active,
)
from monitoring.python_monitoring.scheduler import _monitor_tick_interval


class FakeDatabase:
    def __init__(self) -> None:
        self.executed: list[tuple[str, tuple]] = []
        self.rows: list[dict] = []

    def execute(self, query: str, params: tuple = ()) -> int:
        self.executed.append((query, params))
        return 1

    def query_all(self, _query: str, _params: tuple = ()) -> list[dict]:
        return self.rows


class ReinforcedMonitoringTests(unittest.TestCase):
    def test_default_settings_enable_ten_second_checks_for_fifteen_minutes(self) -> None:
        with patch.dict(os.environ, {}, clear=True):
            settings = reinforced_settings()
        self.assertTrue(settings["enabled"])
        self.assertEqual(settings["duration_sec"], 900)
        self.assertEqual(settings["interval_sec"], 10)

    def test_expired_watch_restores_the_base_interval(self) -> None:
        active = {"ends_at": datetime.now() + timedelta(minutes=5), "interval_sec": 10}
        expired = {"ends_at": datetime.now() - timedelta(seconds=1), "interval_sec": 10}
        self.assertTrue(watch_is_active(active))
        self.assertFalse(watch_is_active(expired))
        self.assertEqual(effective_interval(60, active), 10)
        self.assertEqual(effective_interval(60, expired), 60)

    def test_activation_is_persisted_with_an_expiration(self) -> None:
        database = FakeDatabase()
        result = activate_reinforced_watch(
            database,
            42,
            91,
            "consensus",
            {
                "reinforced_monitoring_enabled": "1",
                "reinforced_monitoring_duration_sec": "600",
                "reinforced_monitor_interval_sec": "15",
            },
        )
        self.assertTrue(result["active"])
        self.assertEqual(result["interval_sec"], 15)
        self.assertGreaterEqual((result["ends_at"] - result["started_at"]).total_seconds(), 600)
        self.assertEqual(database.executed[0][1][0:3], (42, 91, "consensus"))

    def test_active_watches_are_indexed_by_site(self) -> None:
        database = FakeDatabase()
        database.rows = [
            {
                "site_id": 8,
                "incident_id": 12,
                "source_mode": "local",
                "started_at": datetime.now(),
                "ends_at": datetime.now() + timedelta(minutes=5),
                "interval_sec": 10,
            }
        ]
        watches = load_active_watches(database, [8])
        self.assertEqual(watches[8]["incident_id"], 12)

    def test_scheduler_uses_the_reinforced_tick(self) -> None:
        with patch.dict(
            os.environ,
            {
                "INSIGHT_MONITOR_INTERVAL_SEC": "60",
                "INSIGHT_REINFORCED_MONITORING_ENABLED": "1",
                "INSIGHT_REINFORCED_MONITOR_INTERVAL_SEC": "10",
            },
            clear=True,
        ):
            self.assertEqual(_monitor_tick_interval(), 10)


if __name__ == "__main__":
    unittest.main()
