from __future__ import annotations

import hashlib
import hmac
import sys
import unittest
from pathlib import Path


ROOT = Path(__file__).resolve().parents[1]
sys.path.insert(0, str(ROOT / "monitoring"))

from python_monitoring.distributed import (
    consensus_from_observations,
    derive_node_secret,
    format_unix_milliseconds,
    rendezvous_nodes,
    signature_payload,
)


def observation(status: str, response: float = 0) -> dict:
    return {"status": status, "response_time_ms": response, "observed_at": "2026-07-11 12:00:00"}


class DistributedConsensusTests(unittest.TestCase):
    def test_single_online_node(self) -> None:
        result = consensus_from_observations([observation("online", 25)], 1)
        self.assertEqual("online", result["status"])
        self.assertEqual(1.0, result["confidence"])

    def test_single_offline_node(self) -> None:
        self.assertEqual("offline", consensus_from_observations([observation("offline")], 1)["status"])

    def test_split_pair_is_degraded(self) -> None:
        result = consensus_from_observations([observation("online", 20), observation("offline")], 2)
        self.assertEqual("degraded", result["status"])

    def test_healthy_pair_is_online(self) -> None:
        result = consensus_from_observations([observation("online", 20), observation("online", 30)], 2)
        self.assertEqual("online", result["status"])

    def test_regional_disagreement_is_degraded(self) -> None:
        result = consensus_from_observations(
            [observation("online", 10), observation("online", 20), observation("offline")],
            3,
        )
        self.assertEqual("degraded", result["status"])

    def test_majority_failure_is_offline(self) -> None:
        result = consensus_from_observations(
            [observation("online", 10), observation("offline"), observation("offline")],
            3,
        )
        self.assertEqual("offline", result["status"])

    def test_insufficient_responses_are_unknown(self) -> None:
        result = consensus_from_observations([observation("online", 10)], 3)
        self.assertEqual("unknown", result["status"])
        self.assertEqual(2, result["nodes_missing"])

    def test_latency_percentiles(self) -> None:
        result = consensus_from_observations(
            [observation("online", 10), observation("online", 20), observation("online", 100)],
            3,
        )
        self.assertEqual(20.0, result["response_median_ms"])
        self.assertEqual(100.0, result["response_p95_ms"])

    def test_rendezvous_assignment_is_stable(self) -> None:
        nodes = [{"node_key": key} for key in ("paris-1", "frankfurt-1", "montreal-1", "singapore-1")]
        first = rendezvous_nodes(42, nodes, 3)
        second = rendezvous_nodes(42, list(reversed(nodes)), 3)
        self.assertEqual(3, len(first))
        self.assertEqual([node["node_key"] for node in first], [node["node_key"] for node in second])

    def test_node_secret_is_deterministic(self) -> None:
        secret = "a" * 64
        expected = hmac.new(secret.encode(), b"insight-agent-v1:paris-1", hashlib.sha256).hexdigest()
        self.assertEqual(expected, derive_node_secret("paris-1", secret))

    def test_signature_payload_is_stable(self) -> None:
        body = '{"ok":true}'
        expected = "v1\nparis-1\n1234567890\n1234567890abcdef\n" + hashlib.sha256(body.encode()).hexdigest()
        self.assertEqual(expected, signature_payload("paris-1", "1234567890", "1234567890abcdef", body))

    def test_unix_timestamp_keeps_milliseconds(self) -> None:
        self.assertTrue(format_unix_milliseconds(1710000000.123456).endswith(".123"))


if __name__ == "__main__":
    unittest.main()
