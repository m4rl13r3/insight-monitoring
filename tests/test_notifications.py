from __future__ import annotations

import base64
import json
import logging
import os
import shutil
import subprocess
import tempfile
import threading
import unittest
from http.server import BaseHTTPRequestHandler, ThreadingHTTPServer
from pathlib import Path
from unittest.mock import patch

from monitoring.python_monitoring.notifications import (
    _dispatch_subscribers,
    decrypt_config,
    dispatch_event,
    encrypt_config,
    render_templates,
    send_channel,
    subscriber_smtp_config,
)
from monitoring.python_monitoring.monitor import NotificationBatch


class NotificationDatabase:
    def __init__(self, channel: dict):
        self.channel = channel
        self.executed: list[tuple[str, tuple]] = []

    def execute(self, query: str, params: tuple = ()) -> int:
        self.executed.append((query, params))
        return 1

    def query_all(self, query: str, params: tuple = ()) -> list[dict]:
        return [self.channel]

    def query_one(self, query: str, params: tuple = ()) -> dict:
        if "FROM notification_channels" in query:
            return {"config_ciphertext": self.channel["config_ciphertext"]}
        return {
            "title_template": "[{{ app_name }}] {{ domain }}",
            "body_template": "{{ count }} service(s) : {{ sites }}",
        }


class SubscriberDatabase:
    def __init__(self) -> None:
        self.queries: list[tuple[str, tuple]] = []

    def query_all(self, query: str, params: tuple = ()) -> list[dict]:
        self.queries.append((query, params))
        return []

    def execute(self, _query: str, _params: tuple = ()) -> int:
        return 1


class WebhookHandler(BaseHTTPRequestHandler):
    payloads: list[dict] = []

    def do_POST(self) -> None:
        length = int(self.headers.get("Content-Length", "0"))
        self.__class__.payloads.append(json.loads(self.rfile.read(length).decode("utf-8")))
        self.send_response(204)
        self.end_headers()

    def log_message(self, format: str, *args: object) -> None:
        return


class NotificationTests(unittest.TestCase):
    def setUp(self) -> None:
        self.previous_key = os.environ.get("INSIGHT_NOTIFICATION_ENCRYPTION_KEY")
        os.environ["INSIGHT_NOTIFICATION_ENCRYPTION_KEY"] = "abcdef0123456789" * 4
        WebhookHandler.payloads = []
        self.server = ThreadingHTTPServer(("127.0.0.1", 0), WebhookHandler)
        self.thread = threading.Thread(target=self.server.serve_forever, daemon=True)
        self.thread.start()

    def tearDown(self) -> None:
        self.server.shutdown()
        self.server.server_close()
        self.thread.join(timeout=2)
        if self.previous_key is None:
            os.environ.pop("INSIGHT_NOTIFICATION_ENCRYPTION_KEY", None)
        else:
            os.environ["INSIGHT_NOTIFICATION_ENCRYPTION_KEY"] = self.previous_key

    def webhook_url(self) -> str:
        return f"http://127.0.0.1:{self.server.server_port}/alert"

    def test_encrypted_configuration_round_trip(self) -> None:
        source = {"token": "sëcret-token", "targets": ["ops", "on-call"]}
        ciphertext = encrypt_config(source)
        self.assertTrue(ciphertext.startswith("v1:"))
        self.assertNotIn("sëcret-token", ciphertext)
        self.assertEqual(source, decrypt_config(ciphertext))

    def test_tampered_configuration_is_rejected(self) -> None:
        ciphertext = encrypt_config({"token": "secret"})
        encoded = ciphertext[3:]
        encrypted = bytearray(base64.urlsafe_b64decode(encoded + "=" * (-len(encoded) % 4)))
        encrypted[0] ^= 1
        tampered = "v1:" + base64.urlsafe_b64encode(bytes(encrypted)).decode("ascii").rstrip("=")
        with self.assertRaises(Exception):
            decrypt_config(tampered)

    @unittest.skipUnless(shutil.which("php"), "PHP is required to verify encryption compatibility")
    def test_php_and_python_share_the_same_ciphertext_format(self) -> None:
        root = Path(__file__).resolve().parents[1]
        with tempfile.TemporaryDirectory() as temporary:
            environment = os.environ.copy()
            environment.update(
                {
                    "INSIGHT_APP_ENV": "development",
                    "INSIGHT_DEV_AUTH_BYPASS": "1",
                    "INSIGHT_AUTH_DB_PATH": str(Path(temporary) / "auth.sqlite"),
                }
            )
            php_encrypt = subprocess.run(
                [
                    "php",
                    "-r",
                    'require $argv[1]; echo insight_notifications_encrypt(["token" => "shared-sëcret"]);',
                    str(root / "public" / "admin" / "_notifications.php"),
                ],
                capture_output=True,
                text=True,
                check=True,
                env=environment,
            ).stdout
            self.assertEqual("shared-sëcret", decrypt_config(php_encrypt)["token"])
            python_encrypt = encrypt_config({"token": "shared-sëcret"})
            php_decrypt = subprocess.run(
                [
                    "php",
                    "-r",
                    'require $argv[1]; echo insight_notifications_decrypt($argv[2])["token"];',
                    str(root / "public" / "admin" / "_notifications.php"),
                    python_encrypt,
                ],
                capture_output=True,
                text=True,
                check=True,
                env=environment,
            ).stdout
            self.assertEqual("shared-sëcret", php_decrypt)

    def test_liquid_templates_render_conditions(self) -> None:
        rendered = render_templates(
            "monitor_down",
            {"app_name": "Insight", "count": 2, "domain": "api.example.com"},
            {
                "title": "[{{ app_name }}] {{ domain }}",
                "body": "{{ count }} service{% if count > 1 %}s are unavailable{% endif %}.",
            },
        )
        self.assertEqual("[Insight] api.example.com", rendered["title"])
        self.assertEqual("2 services are unavailable.", rendered["body"])

    def test_webhook_channel_receives_structured_payload(self) -> None:
        result = send_channel(
            {"name": "Local webhook", "provider": "webhook", "config": {"url": self.webhook_url()}},
            "monitor_down",
            {"app_name": "Insight", "domain": "api.example.com", "sites": "api.example.com", "count": 1},
            {"title": "{{ domain }} is offline", "body": "{{ sites }} no longer responds."},
        )
        self.assertTrue(result["ok"])
        self.assertEqual("monitor_down", WebhookHandler.payloads[0]["event"])
        self.assertEqual("api.example.com is offline", WebhookHandler.payloads[0]["title"])

    def test_dispatch_records_delivery_and_honors_subscription(self) -> None:
        channel = {
            "id": 7,
            "name": "Local webhook",
            "provider": "webhook",
            "enabled": 1,
            "config_ciphertext": encrypt_config({"url": self.webhook_url()}),
            "events_json": json.dumps(["monitor_down"]),
        }
        database = NotificationDatabase(channel)
        result = dispatch_event(
            database,
            {"disable_notifications": "0"},
            "monitor_down",
            {"app_name": "Insight", "domain": "api.example.com", "sites": "api.example.com", "count": 1},
        )
        self.assertTrue(result["ok"])
        self.assertEqual(1, result["sent"])
        self.assertTrue(any("INSERT INTO notification_deliveries" in query for query, _ in database.executed))

    def test_internal_escalation_does_not_target_public_subscribers(self) -> None:
        result = _dispatch_subscribers(
            object(),
            {"status_subscriptions_enabled": "1"},
            "incident_open",
            {"notify_subscribers": False},
            "oncall:42",
        )
        self.assertEqual({"targeted": 0, "sent": 0, "failed": 0}, result)

    def test_status_subscribers_reuse_a_tested_smtp_channel(self) -> None:
        config = {
            "host": "smtp.example.test",
            "port": 465,
            "username": "alerts@example.test",
            "password": "secret",
            "encryption": "ssl",
            "from_name": "Insight",
            "from_email": "alerts@example.test",
            "to": "operations@example.test",
        }
        database = NotificationDatabase({"config_ciphertext": encrypt_config(config)})
        self.assertEqual(config, subscriber_smtp_config(database, {}))

    def test_empty_custom_status_page_does_not_receive_unrelated_events(self) -> None:
        database = SubscriberDatabase()
        with patch("monitoring.python_monitoring.notifications.subscriber_smtp_config", return_value={"host": "smtp.example.test"}):
            result = _dispatch_subscribers(
                database,
                {"status_subscriptions_enabled": "1"},
                "incident_open",
                {"site_ids": [42]},
                "incident:81:open",
            )
        self.assertEqual(0, result["targeted"])
        subscriber_query = next(query for query, _ in database.queries if "FROM status_page_subscribers" in query)
        self.assertIn("p.slug='default'", subscriber_query)

    def test_monitor_batch_maps_every_status_to_a_notification_event(self) -> None:
        batch = NotificationBatch()
        batch.queue_incident_open("https://api.example.com")
        batch.queue_incident_close(
            "https://status.example.com",
            "The service returned to stable availability after the restart.",
            False,
        )
        batch.queue_status_offline("https://edge.example.com")
        batch.queue_status_online("https://www.example.com")
        with patch(
            "monitoring.python_monitoring.monitor.dispatch_event",
            return_value={"ok": True, "configured": 1, "sent": 1, "failed": 0},
        ) as dispatch:
            batch.flush(object(), {"sms": [], "emails": []}, {"app_name": "Insight"}, logging.getLogger("test"))
        self.assertEqual(
            ["incident_open", "incident_resolved", "monitor_down", "monitor_up"],
            [call.args[2] for call in dispatch.call_args_list],
        )


if __name__ == "__main__":
    unittest.main()
