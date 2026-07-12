from __future__ import annotations

import unittest

from monitoring.python_monitoring.actions import _normalize_url


class ActionTargetTests(unittest.TestCase):
    def test_icmp_hostname_stays_a_hostname(self) -> None:
        self.assertEqual(_normalize_url("Server.Example.com", "icmp"), "server.example.com")

    def test_icmp_ipv4_stays_an_address(self) -> None:
        self.assertEqual(_normalize_url("192.0.2.10", "icmp"), "192.0.2.10")

    def test_icmp_ipv6_is_normalized(self) -> None:
        self.assertEqual(_normalize_url("[2001:0db8::1]", "icmp"), "2001:db8::1")

    def test_icmp_rejects_urls_and_ports(self) -> None:
        self.assertEqual(_normalize_url("https://example.com", "icmp"), "")
        self.assertEqual(_normalize_url("example.com:443", "icmp"), "")


if __name__ == "__main__":
    unittest.main()
