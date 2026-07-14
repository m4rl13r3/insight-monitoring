from __future__ import annotations

import json
import unittest
from datetime import datetime

from monitoring.python_monitoring.hourly import _compute_weighted_hour_metrics, _ensure_calc_settings_table, process_hourly


class FakeDatabase:
    def __init__(self, previous: dict | None, rows: list[dict]):
        self.previous = previous
        self.rows = rows
        self.executions: list[tuple[str, tuple]] = []

    def query_one(self, _sql: str, _params: tuple = ()) -> dict | None:
        return self.previous

    def query_all(self, _sql: str, _params: tuple = ()) -> list[dict]:
        return self.rows

    def execute(self, sql: str, params: tuple = ()) -> int:
        self.executions.append((sql, tuple(params)))
        return 1


class CalculationMethodTests(unittest.TestCase):
    def test_automatic_default_uses_verified_duration(self) -> None:
        db = FakeDatabase(None, [])
        settings = _ensure_calc_settings_table(db)
        self.assertEqual(settings["default_calc_method"], "interval_capped")
        self.assertIn("interval_capped", db.executions[-1][0])

    def test_time_weighted_uses_observation_duration(self) -> None:
        db = FakeDatabase(
            {"checked_at": "2026-07-13 23:59:00", "status": "online"},
            [{"checked_at": "2026-07-14 00:30:00", "status": "offline", "response_time": None}],
        )
        metrics = _compute_weighted_hour_metrics(
            db,
            1,
            datetime(2026, 7, 14, 0, 0, 0),
            datetime(2026, 7, 14, 1, 0, 0),
            probes_table_sql="`probes`",
        )
        self.assertEqual(metrics["offline_seconds"], 1800)
        self.assertEqual(metrics["availability_ratio"], 0.5)

    def test_interval_cap_marks_collection_gaps_unknown(self) -> None:
        db = FakeDatabase(
            {"checked_at": "2026-07-13 23:59:00", "status": "online"},
            [{"checked_at": "2026-07-14 00:30:00", "status": "offline", "response_time": None}],
        )
        metrics = _compute_weighted_hour_metrics(
            db,
            1,
            datetime(2026, 7, 14, 0, 0, 0),
            datetime(2026, 7, 14, 1, 0, 0),
            probes_table_sql="`probes`",
            validity_cap_sec=120,
        )
        self.assertEqual(metrics["offline_seconds"], 120)
        self.assertEqual(metrics["unknown_seconds"], 3420)
        self.assertEqual(metrics["availability_ratio"], round(60 / 180, 4))

    def test_sample_ratio_weights_each_observation_equally(self) -> None:
        db = FakeDatabase(
            None,
            [
                {"checked_at": "2026-07-14 00:01:00", "status": "online", "response_time": 10},
                {"checked_at": "2026-07-14 00:02:00", "status": "online", "response_time": 20},
                {"checked_at": "2026-07-14 00:03:00", "status": "degraded", "response_time": 30},
                {"checked_at": "2026-07-14 00:04:00", "status": "offline", "response_time": None},
            ],
        )
        process_hourly(db, 1, "2026-07-14", 0, "sample_ratio", 60, probes_table_sql="`probes`", hourly_table_sql="`hourly_stats`")
        params = db.executions[-1][1]
        self.assertEqual(params[13], 0.75)
        self.assertEqual(params[14], 240)
        self.assertEqual(params[15], 0.625)
        self.assertEqual(json.loads(params[17])["eligible_samples"], 4)

    def test_strict_sla_counts_unknown_as_unavailable(self) -> None:
        db = FakeDatabase(None, [])
        process_hourly(db, 1, "2026-07-14", 0, "strict_sla", 60, probes_table_sql="`probes`", hourly_table_sql="`hourly_stats`")
        params = db.executions[-1][1]
        self.assertEqual(params[13], 0.0)
        self.assertEqual(params[14], 3600)
        self.assertEqual(params[15], 0.0)


if __name__ == "__main__":
    unittest.main()
