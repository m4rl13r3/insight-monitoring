from __future__ import annotations

import unittest

from monitoring.python_monitoring.declarative import normalize_configuration, render_configuration


class DeclarativeConfigurationTests(unittest.TestCase):
    def test_normalizes_monitor_and_page_references(self) -> None:
        document = normalize_configuration(
            {
                "version": 1,
                "monitors": [
                    {
                        "target": "https://example.com",
                        "type": "http",
                        "interval_seconds": 60,
                        "calculation": "interval_capped",
                    }
                ],
                "runbooks": [{"slug": "http-outage", "name": "HTTP outage", "content": "Check the origin."}],
                "status_pages": [
                    {
                        "slug": "default",
                        "name": "Insight",
                        "monitors": ["http:https://example.com"],
                    }
                ],
            }
        )
        self.assertEqual(document["monitors"][0]["calc_method"], "interval_capped")
        self.assertEqual(document["status_pages"][0]["monitors"], ["http:https://example.com"])
        self.assertIn("version: 1", render_configuration(document))

    def test_rejects_unknown_page_monitor(self) -> None:
        with self.assertRaisesRegex(ValueError, "Unknown or duplicate monitor"):
            normalize_configuration(
                {
                    "version": 1,
                    "monitors": [],
                    "status_pages": [{"slug": "default", "name": "Insight", "monitors": ["http:https://missing.example"]}],
                }
            )

    def test_rejects_unsupported_method(self) -> None:
        with self.assertRaisesRegex(ValueError, "Unsupported calculation method"):
            normalize_configuration(
                {
                    "version": 1,
                    "monitors": [{"target": "https://example.com", "type": "http", "calculation": "optimistic"}],
                }
            )


if __name__ == "__main__":
    unittest.main()
