from __future__ import annotations

import json
import sys
import tempfile
import unittest
from datetime import datetime
from pathlib import Path


ROOT = Path(__file__).resolve().parents[1]
sys.path.insert(0, str(ROOT / "monitoring" / "agent"))
sys.path.insert(0, str(ROOT / "monitoring"))

from agent import InsightAgent, Spool
from python_monitoring.actions import _normalize_probe_type, _normalize_url
from python_monitoring.hourly import _compute_weighted_hour_metrics
from python_monitoring.monitor import _extract_tcp_target, check_tcp_status, run_manual_check


class FakeDatabase:
    def __init__(self, rows: list[dict]):
        self.rows = rows

    def query_one(self, query: str, params: tuple) -> None:
        return None

    def query_all(self, query: str, params: tuple) -> list[dict]:
        return self.rows


class AgentSpoolTests(unittest.TestCase):
    def observation(self, sample_id: str) -> dict:
        return {
            "error_code": None,
            "error_message": None,
            "http_code": 200,
            "metadata": {"adapter": "native"},
            "observed_at": "2026-07-11T12:00:00.000Z",
            "response_time_ms": 42,
            "sample_id": sample_id,
            "site_id": 1,
            "status": "online",
        }

    def test_batch_survives_restart_byte_for_byte(self) -> None:
        with tempfile.TemporaryDirectory() as temporary:
            path = Path(temporary) / "spool.sqlite"
            spool = Spool(path, 1000)
            spool.enqueue(self.observation("sample-0000000001"))
            batch_id = spool.prepare_batch("paris-1", {"display_name": "Paris"}, 200)
            first = spool.next_batch(9999999999)
            self.assertIsNotNone(batch_id)
            self.assertIsNotNone(first)
            payload = str(first["payload_json"])
            spool.close()

            reopened = Spool(path, 1000)
            second = reopened.next_batch(9999999999)
            self.assertIsNotNone(second)
            self.assertEqual(payload, str(second["payload_json"]))
            self.assertEqual(batch_id, str(second["batch_id"]))
            reopened.complete_batch(str(batch_id))
            self.assertEqual({"samples": 0, "batches": 0}, reopened.stats())
            reopened.close()

    def test_batch_payload_contains_ordered_observations(self) -> None:
        with tempfile.TemporaryDirectory() as temporary:
            spool = Spool(Path(temporary) / "spool.sqlite", 1000)
            spool.enqueue(self.observation("sample-0000000001"))
            spool.enqueue(self.observation("sample-0000000002"))
            spool.prepare_batch("paris-1", {"display_name": "Paris"}, 200)
            row = spool.next_batch(9999999999)
            payload = json.loads(str(row["payload_json"]))
            self.assertEqual(
                ["sample-0000000001", "sample-0000000002"],
                [item["sample_id"] for item in payload["observations"]],
            )
            spool.close()

    def test_only_one_batch_can_be_in_flight(self) -> None:
        with tempfile.TemporaryDirectory() as temporary:
            spool = Spool(Path(temporary) / "spool.sqlite", 1000)
            spool.enqueue(self.observation("sample-0000000001"))
            spool.enqueue(self.observation("sample-0000000002"))
            first_batch = spool.prepare_batch("paris-1", {"display_name": "Paris"}, 1)
            self.assertIsNotNone(first_batch)
            self.assertIsNone(spool.prepare_batch("paris-1", {"display_name": "Paris"}, 1))
            spool.complete_batch(str(first_batch))
            self.assertIsNotNone(spool.prepare_batch("paris-1", {"display_name": "Paris"}, 1))
            spool.close()


class AgentProbeTests(unittest.TestCase):
    def test_transient_failure_is_retried(self) -> None:
        instance = InsightAgent.__new__(InsightAgent)
        instance.probe_retries = 1
        instance.probe_retry_delay_ms = 0
        attempts = []

        def probe_once(target: dict) -> dict:
            attempts.append(target)
            return {
                "http_code": 503 if len(attempts) == 1 else 200,
                "metadata": {"adapter": "test"},
                "response_time_ms": 20,
                "status": "offline" if len(attempts) == 1 else "online",
            }

        instance.probe_once = probe_once
        result = instance.probe({"site_id": 1})
        self.assertEqual("online", result["status"])
        self.assertEqual(2, result["metadata"]["attempts"])

    def test_blackbox_targets_keep_their_native_shape(self) -> None:
        self.assertEqual("tcp", _normalize_probe_type("TCP"))
        self.assertEqual("cache.example.com:6379", _normalize_url("cache.example.com:6379", "tcp"))
        self.assertEqual("https://example.com", _normalize_url("example.com", "http"))

    def test_tcp_probe_is_native(self) -> None:
        import socket

        listener = socket.socket(socket.AF_INET, socket.SOCK_STREAM)
        listener.bind(("127.0.0.1", 0))
        listener.listen(5)
        port = int(listener.getsockname()[1])
        try:
            self.assertEqual(("127.0.0.1", port), _extract_tcp_target(f"127.0.0.1:{port}"))
            self.assertEqual("online", check_tcp_status("127.0.0.1", port)["status"])
            result = run_manual_check(f"127.0.0.1:{port}", "tcp", {})
            self.assertTrue(result["ok"])
            self.assertEqual("tcp", result["probe_type"])
            self.assertEqual("online", result["result"]["status"])
        finally:
            listener.close()


class WeightedAvailabilityTests(unittest.TestCase):
    def test_unknown_time_is_not_counted_as_downtime(self) -> None:
        start = datetime(2026, 7, 11, 12, 0, 0)
        end = datetime(2026, 7, 11, 13, 0, 0)
        database = FakeDatabase([{"checked_at": datetime(2026, 7, 11, 12, 30, 0), "status": "online"}])
        metrics = _compute_weighted_hour_metrics(
            database,
            1,
            start,
            end,
            probes_table_sql="`probes`",
        )
        self.assertEqual(0, metrics["offline_seconds"])
        self.assertEqual(1800, metrics["unknown_seconds"])
        self.assertEqual(1.0, metrics["availability_ratio"])

    def test_known_offline_time_stays_downtime(self) -> None:
        start = datetime(2026, 7, 11, 12, 0, 0)
        end = datetime(2026, 7, 11, 13, 0, 0)
        database = FakeDatabase([{"checked_at": datetime(2026, 7, 11, 12, 30, 0), "status": "offline"}])
        metrics = _compute_weighted_hour_metrics(
            database,
            1,
            start,
            end,
            probes_table_sql="`probes`",
        )
        self.assertEqual(1800, metrics["offline_seconds"])
        self.assertEqual(1800, metrics["unknown_seconds"])
        self.assertEqual(0.0, metrics["availability_ratio"])


if __name__ == "__main__":
    unittest.main()
