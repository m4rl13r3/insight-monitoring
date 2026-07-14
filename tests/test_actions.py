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

    def test_specialized_targets_are_normalized(self) -> None:
        self.assertEqual(_normalize_url("api.example.com:50051", "grpc"), "grpc://api.example.com:50051")
        self.assertEqual(_normalize_url("cache.example.com:6379/0", "redis"), "redis://cache.example.com:6379/0")
        self.assertEqual(_normalize_url("mail.example.com:587", "smtp"), "smtp://mail.example.com:587")
        self.assertEqual(_normalize_url("mq.example.com:5672/production", "rabbitmq"), "amqp://mq.example.com:5672/production")
        self.assertEqual(_normalize_url("switch.example.com:161", "snmp"), "snmp://switch.example.com:161")

    def test_agent_service_target_is_bound_to_one_agent(self) -> None:
        self.assertEqual(_normalize_url("agent://paris-1/systemd/nginx.service", "service"), "agent://paris-1/systemd/nginx.service")
        self.assertEqual(_normalize_url("agent://paris-1/systemd/../../bin/sh", "service"), "")


if __name__ == "__main__":
    unittest.main()
