from __future__ import annotations

import base64
import hashlib
import html
import json
import os
import smtplib
import ssl
from datetime import datetime, timezone
from email.message import EmailMessage
from typing import Any, Dict, Iterable, List
from urllib.request import Request, urlopen

import apprise
from liquid import Environment
from nacl.secret import SecretBox

from .alerts import send_sms
from .db import Database


EVENT_KEYS = {"test", "monitor_down", "monitor_up", "incident_open", "incident_resolved"}
DEFAULT_EVENTS = ["monitor_down", "monitor_up", "incident_open", "incident_resolved"]
APPRISE_PROVIDERS = {
    "apprise",
    "discord",
    "telegram",
    "slack",
    "teams",
    "google_chat",
    "ntfy",
    "gotify",
    "pushover",
    "pagerduty",
    "opsgenie",
    "matrix",
    "signal",
    "mattermost",
    "rocket_chat",
    "home_assistant",
    "sms",
    "whatsapp",
    "twilio",
    "mailgun",
    "sendgrid",
    "pushbullet",
    "pagertree",
    "webex",
    "power_automate",
}
DEFAULT_TEMPLATES = {
    "test": {
        "title": "[{{ app_name }}] Test from {{ channel_name }}",
        "body": "This is a test message sent by {{ app_name }} at {{ timestamp }}.",
    },
    "monitor_down": {
        "title": "[{{ app_name }}] {{ domain }} is offline",
        "body": "{{ count }} service{% if count > 1 %}s are{% else %} is{% endif %} unavailable: {{ sites }}. {{ message }}",
    },
    "monitor_up": {
        "title": "[{{ app_name }}] {{ domain }} is back online",
        "body": "{{ count }} service{% if count > 1 %}s are{% else %} is{% endif %} back online: {{ sites }}. {{ message }}",
    },
    "incident_open": {
        "title": "[{{ app_name }}] Incident opened - {{ domain }}",
        "body": "An incident is open for {{ sites }}. {{ message }}",
    },
    "incident_resolved": {
        "title": "[{{ app_name }}] Incident resolved - {{ domain }}",
        "body": "The incident affecting {{ sites }} is resolved. {{ message }}",
    },
}


def _encryption_key() -> bytes:
    raw = str(os.getenv("INSIGHT_NOTIFICATION_ENCRYPTION_KEY") or "").strip()
    if len(raw) < 32:
        raise RuntimeError("INSIGHT_NOTIFICATION_ENCRYPTION_KEY must contain at least 32 characters.")
    if len(raw) == 64:
        try:
            decoded = bytes.fromhex(raw)
            if len(decoded) == SecretBox.KEY_SIZE:
                return decoded
        except ValueError:
            pass
    try:
        decoded = base64.urlsafe_b64decode(raw + "=" * (-len(raw) % 4))
        if len(decoded) == SecretBox.KEY_SIZE:
            return decoded
    except Exception:
        pass
    return hashlib.sha256(raw.encode("utf-8")).digest()


def encrypt_config(config: Dict[str, Any]) -> str:
    payload = json.dumps(config, ensure_ascii=False, separators=(",", ":"), sort_keys=True).encode("utf-8")
    encrypted = SecretBox(_encryption_key()).encrypt(payload)
    return "v1:" + base64.urlsafe_b64encode(bytes(encrypted)).decode("ascii").rstrip("=")


def decrypt_config(token: str) -> Dict[str, Any]:
    raw = str(token or "").strip()
    if not raw.startswith("v1:"):
        raise ValueError("Unknown encrypted configuration.")
    encoded = raw[3:]
    encrypted = base64.urlsafe_b64decode(encoded + "=" * (-len(encoded) % 4))
    decoded = SecretBox(_encryption_key()).decrypt(encrypted)
    config = json.loads(decoded.decode("utf-8"))
    if not isinstance(config, dict):
        raise ValueError("Invalid channel configuration.")
    return config


def ensure_notification_schema(db: Database) -> None:
    db.execute(
        """
        CREATE TABLE IF NOT EXISTS notification_channels (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            name VARCHAR(120) NOT NULL,
            provider VARCHAR(40) NOT NULL,
            enabled TINYINT(1) NOT NULL DEFAULT 1,
            config_ciphertext LONGTEXT NOT NULL,
            events_json TEXT NOT NULL,
            last_test_at DATETIME NULL,
            last_status VARCHAR(16) NOT NULL DEFAULT 'unknown',
            last_error VARCHAR(255) NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            KEY idx_notification_channels_enabled (enabled, provider),
            KEY idx_notification_channels_status (last_status, updated_at)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        """
    )
    db.execute(
        """
        CREATE TABLE IF NOT EXISTS notification_templates (
            event_key VARCHAR(40) NOT NULL,
            title_template VARCHAR(500) NOT NULL,
            body_template TEXT NOT NULL,
            updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (event_key)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        """
    )
    db.execute(
        """
        CREATE TABLE IF NOT EXISTS notification_deliveries (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            channel_id BIGINT UNSIGNED NULL,
            event_key VARCHAR(40) NOT NULL,
            status ENUM('sent','failed','skipped') NOT NULL,
            title_rendered VARCHAR(500) NULL,
            error_message VARCHAR(255) NULL,
            attempted_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            KEY idx_notification_deliveries_channel (channel_id, attempted_at),
            KEY idx_notification_deliveries_status (status, attempted_at)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        """
    )
    for event_key, templates in DEFAULT_TEMPLATES.items():
        db.execute(
            """
            INSERT INTO notification_templates (event_key, title_template, body_template)
            VALUES (%s, %s, %s)
            ON DUPLICATE KEY UPDATE event_key = VALUES(event_key)
            """,
            (event_key, templates["title"], templates["body"]),
        )


def has_notification_channels(db: Database) -> bool:
    try:
        ensure_notification_schema(db)
        row = db.query_one("SELECT COUNT(*) AS total FROM notification_channels WHERE enabled = 1")
        return int((row or {}).get("total") or 0) > 0
    except Exception:
        return False


def _event_type(event_key: str) -> apprise.NotifyType:
    if event_key in {"monitor_down", "incident_open"}:
        return apprise.NotifyType.FAILURE
    if event_key in {"monitor_up", "incident_resolved"}:
        return apprise.NotifyType.SUCCESS
    return apprise.NotifyType.INFO


def _render(template: str, context: Dict[str, Any], maximum: int) -> str:
    environment = Environment()
    rendered = environment.from_string(str(template or "")).render(**context).strip()
    return rendered[:maximum]


def _split_values(value: Any) -> List[str]:
    if isinstance(value, list):
        values = value
    else:
        values = str(value or "").replace(";", "\n").splitlines()
    return [str(item).strip() for item in values if str(item).strip()]


def _split_recipients(value: Any) -> List[str]:
    if isinstance(value, list):
        values = value
    else:
        values = str(value or "").replace(";", "\n").replace(",", "\n").splitlines()
    return [str(item).strip() for item in values if str(item).strip()]


def _send_smtp(config: Dict[str, Any], title: str, body: str) -> tuple[bool, str]:
    host = str(config.get("host") or "").strip()
    username = str(config.get("username") or "").strip()
    password = str(config.get("password") or "")
    from_email = str(config.get("from_email") or username).strip()
    from_name = str(config.get("from_name") or "Insight").strip()
    recipients = _split_recipients(config.get("to"))
    if not host or not from_email or not recipients:
        return False, "Missing SMTP server, sender, or recipient."
    try:
        port = int(config.get("port") or 465)
    except (TypeError, ValueError):
        port = 465
    message = EmailMessage()
    message["From"] = f"{from_name} <{from_email}>"
    message["To"] = ", ".join(recipients)
    message["Subject"] = title
    message.set_content(body)
    message.add_alternative(f"<html><body><p>{html.escape(body).replace(chr(10), '<br>')}</p></body></html>", subtype="html")
    encryption = str(config.get("encryption") or "ssl").lower()
    try:
        if encryption == "ssl":
            with smtplib.SMTP_SSL(host, port, timeout=15, context=ssl.create_default_context()) as smtp:
                if username:
                    smtp.login(username, password)
                smtp.send_message(message)
        else:
            with smtplib.SMTP(host, port, timeout=15) as smtp:
                smtp.ehlo()
                if encryption in {"tls", "starttls"}:
                    smtp.starttls(context=ssl.create_default_context())
                    smtp.ehlo()
                if username:
                    smtp.login(username, password)
                smtp.send_message(message)
        return True, "SMTP message sent."
    except Exception as exc:
        return False, str(exc)[:255]


def _send_webhook(config: Dict[str, Any], event_key: str, title: str, body: str, context: Dict[str, Any]) -> tuple[bool, str]:
    url = str(config.get("url") or "").strip()
    if not url.startswith(("http://", "https://")):
        return False, "Invalid webhook URL."
    method = str(config.get("method") or "POST").strip().upper()
    if method not in {"POST", "PUT", "PATCH"}:
        method = "POST"
    headers = {"Content-Type": "application/json", "User-Agent": "Insight-Notifications/0.1"}
    configured_headers = config.get("headers")
    if isinstance(configured_headers, str) and configured_headers.strip():
        configured_headers = json.loads(configured_headers)
    if isinstance(configured_headers, dict):
        for key, value in configured_headers.items():
            safe_key = str(key).strip()
            if safe_key and "\n" not in safe_key and "\r" not in safe_key:
                headers[safe_key] = str(value).replace("\r", "").replace("\n", "")
    payload_template = str(config.get("payload_template") or "").strip()
    if payload_template:
        rendered_payload = _render(payload_template, {**context, "title": title, "body": body}, 65535)
        payload = json.loads(rendered_payload)
    else:
        payload = {"event": event_key, "title": title, "message": body, "context": context}
    request = Request(url, data=json.dumps(payload, ensure_ascii=False).encode("utf-8"), headers=headers, method=method)
    try:
        with urlopen(request, timeout=15) as response:
            status = int(getattr(response, "status", 0) or 0)
            if 200 <= status < 300:
                return True, f"Webhook HTTP {status}."
            return False, f"Webhook HTTP {status}."
    except Exception as exc:
        return False, str(exc)[:255]


def _send_apprise(config: Dict[str, Any], title: str, body: str, event_key: str) -> tuple[bool, str]:
    urls = _split_values(config.get("urls"))
    if not urls:
        return False, "No Apprise URL configured."
    notifier = apprise.Apprise()
    accepted = sum(1 for url in urls if notifier.add(url))
    if accepted == 0:
        return False, "No valid Apprise URL."
    sent = bool(notifier.notify(title=title, body=body, notify_type=_event_type(event_key)))
    return (sent, f"Apprise processed {accepted} destination(s)." if sent else "Apprise delivery failed.")


def render_templates(event_key: str, context: Dict[str, Any], templates: Dict[str, str], channel_name: str = "Insight") -> Dict[str, str]:
    normalized_context = dict(context)
    normalized_context.setdefault("app_name", "Insight")
    normalized_context.setdefault("public_url", "")
    normalized_context.setdefault("timestamp", datetime.now(timezone.utc).isoformat(timespec="seconds"))
    normalized_context.setdefault("event", event_key)
    normalized_context.setdefault("channel_name", channel_name)
    normalized_context.setdefault("domain", "service")
    normalized_context.setdefault("sites", normalized_context.get("site_url") or "service")
    normalized_context.setdefault("site_url", normalized_context.get("sites") or "service")
    normalized_context.setdefault("count", 1)
    normalized_context.setdefault("status", "test" if event_key == "test" else "unknown")
    normalized_context.setdefault("message", "")
    return {
        "title": _render(templates.get("title", "{{ app_name }}"), normalized_context, 500),
        "body": _render(templates.get("body", "{{ message }}"), normalized_context, 10000),
    }


def send_channel(channel: Dict[str, Any], event_key: str, context: Dict[str, Any], templates: Dict[str, str]) -> Dict[str, Any]:
    provider = str(channel.get("provider") or "apprise").strip().lower()
    config = channel.get("config") if isinstance(channel.get("config"), dict) else {}
    try:
        rendered = render_templates(event_key, context, templates, str(channel.get("name") or provider))
        title = rendered["title"]
        body = rendered["body"]
        if provider == "smtp":
            sent, details = _send_smtp(config, title, body)
        elif provider == "webhook":
            webhook_context = dict(context)
            webhook_context.setdefault("app_name", "Insight")
            webhook_context.setdefault("event", event_key)
            webhook_context.setdefault("channel_name", str(channel.get("name") or provider))
            sent, details = _send_webhook(config, event_key, title, body, webhook_context)
        elif provider == "free_mobile":
            sent = send_sms(str(config.get("user") or ""), str(config.get("password") or ""), body[:480])
            details = "Free Mobile SMS sent." if sent else "Free Mobile SMS failed."
        elif provider in APPRISE_PROVIDERS:
            sent, details = _send_apprise(config, title, body, event_key)
        else:
            return {"ok": False, "title": title, "error": "Unknown notification provider."}
        return {"ok": bool(sent), "title": title, "details": details, "error": "" if sent else details}
    except Exception as exc:
        return {"ok": False, "title": "", "error": str(exc)[:255]}


def _events_from_value(value: Any) -> List[str]:
    if isinstance(value, str):
        try:
            value = json.loads(value)
        except json.JSONDecodeError:
            value = []
    if not isinstance(value, list):
        return DEFAULT_EVENTS.copy()
    return [event for event in value if isinstance(event, str) and event in EVENT_KEYS and event != "test"]


def _template_for_event(db: Database, event_key: str) -> Dict[str, str]:
    row = db.query_one(
        "SELECT title_template, body_template FROM notification_templates WHERE event_key = %s LIMIT 1",
        (event_key,),
    )
    fallback = DEFAULT_TEMPLATES.get(event_key, DEFAULT_TEMPLATES["test"])
    return {
        "title": str((row or {}).get("title_template") or fallback["title"]),
        "body": str((row or {}).get("body_template") or fallback["body"]),
    }


def dispatch_event(
    db: Database,
    cfg: Dict[str, str],
    event_key: str,
    context: Dict[str, Any],
    *,
    force: bool = False,
    channel_ids: Iterable[int] | None = None,
) -> Dict[str, Any]:
    if event_key not in EVENT_KEYS:
        return {"ok": False, "configured": 0, "sent": 0, "failed": 0, "error": "Unknown event."}
    ensure_notification_schema(db)
    params: List[Any] = []
    where = "enabled = 1"
    ids = [int(value) for value in (channel_ids or []) if int(value) > 0]
    if ids:
        where = "id IN (" + ",".join(["%s"] * len(ids)) + ")"
        params.extend(ids)
    channels = db.query_all(
        f"SELECT id, name, provider, enabled, config_ciphertext, events_json FROM notification_channels WHERE {where} ORDER BY id",
        tuple(params),
    )
    if not force and str(cfg.get("disable_notifications") or "1").strip().lower() in {"1", "true", "yes", "on"}:
        return {"ok": True, "disabled": True, "configured": len(channels), "targeted": 0, "sent": 0, "failed": 0, "results": []}
    templates = _template_for_event(db, event_key)
    results = []
    sent_count = 0
    failed_count = 0
    targeted = 0
    for channel in channels:
        events = _events_from_value(channel.get("events_json"))
        if event_key != "test" and event_key not in events:
            continue
        targeted += 1
        channel_id = int(channel.get("id") or 0)
        try:
            config = decrypt_config(str(channel.get("config_ciphertext") or ""))
            result = send_channel({**channel, "config": config}, event_key, context, templates)
        except Exception as exc:
            result = {"ok": False, "title": "", "error": str(exc)[:255]}
        status = "sent" if result.get("ok") else "failed"
        error = "" if result.get("ok") else str(result.get("error") or "Delivery failed.")[:255]
        db.execute(
            "INSERT INTO notification_deliveries (channel_id, event_key, status, title_rendered, error_message) VALUES (%s, %s, %s, %s, %s)",
            (channel_id, event_key, status, str(result.get("title") or "")[:500], error or None),
        )
        db.execute(
            "UPDATE notification_channels SET last_test_at = IF(%s = 'test', NOW(), last_test_at), last_status = %s, last_error = %s WHERE id = %s",
            (event_key, "success" if result.get("ok") else "error", error or None, channel_id),
        )
        if result.get("ok"):
            sent_count += 1
        else:
            failed_count += 1
        results.append({"channel_id": channel_id, "name": channel.get("name"), **result})
    try:
        db.execute("DELETE FROM notification_deliveries WHERE attempted_at < DATE_SUB(NOW(), INTERVAL 90 DAY)")
    except Exception:
        pass
    return {
        "ok": failed_count == 0,
        "configured": len(channels),
        "targeted": targeted,
        "sent": sent_count,
        "failed": failed_count,
        "results": results,
    }
