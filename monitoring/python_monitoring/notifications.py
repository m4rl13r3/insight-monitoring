from __future__ import annotations

import base64
import hashlib
import hmac
import html
import json
import os
import smtplib
import ssl
from datetime import datetime, timezone
from email.message import EmailMessage
from functools import lru_cache
from pathlib import Path
from typing import Any, Dict, Iterable, List
from urllib.request import Request, urlopen

import apprise
from liquid import Environment
from nacl.secret import SecretBox

from .alerts import send_sms
from .db import Database


EVENT_KEYS = {"test", "monitor_down", "monitor_up", "incident_open", "incident_update", "incident_acknowledged", "incident_resolved", "tls_expiring", "tls_invalid", "maintenance_started", "maintenance_ended"}
DEFAULT_EVENTS = ["monitor_down", "monitor_up", "incident_open", "incident_update", "incident_acknowledged", "incident_resolved", "tls_expiring", "tls_invalid", "maintenance_started", "maintenance_ended"]
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
    "incident_update": {
        "title": "[{{ app_name }}] Incident update - {{ domain }}",
        "body": "A new update was published for {{ sites }}. {{ message }}",
    },
    "incident_acknowledged": {
        "title": "[{{ app_name }}] Incident acknowledged - {{ domain }}",
        "body": "The incident affecting {{ sites }} was acknowledged. {{ message }}",
    },
    "tls_expiring": {
        "title": "[{{ app_name }}] TLS certificate expires soon - {{ domain }}",
        "body": "The TLS certificate for {{ sites }} expires in {{ days_remaining }} days. {{ message }}",
    },
    "tls_invalid": {
        "title": "[{{ app_name }}] Invalid TLS certificate - {{ domain }}",
        "body": "The TLS certificate for {{ sites }} is invalid. {{ message }}",
    },
    "maintenance_started": {
        "title": "[{{ app_name }}] Maintenance started - {{ domain }}",
        "body": "Scheduled maintenance has started for {{ sites }}. {{ message }}",
    },
    "maintenance_ended": {
        "title": "[{{ app_name }}] Maintenance completed - {{ domain }}",
        "body": "Scheduled maintenance has completed for {{ sites }}. {{ message }}",
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
            minimum_severity ENUM('info','minor','major','critical') NOT NULL DEFAULT 'info',
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
    db.execute(
        """
        CREATE TABLE IF NOT EXISTS status_page_subscriber_deliveries (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            subscriber_id BIGINT UNSIGNED NOT NULL,
            event_key VARCHAR(40) NOT NULL,
            idempotency_key CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
            status ENUM('sent','failed') NOT NULL,
            error_message VARCHAR(255) NULL,
            attempted_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            UNIQUE KEY uniq_status_page_subscriber_delivery (subscriber_id, idempotency_key),
            KEY idx_status_page_subscriber_deliveries_time (attempted_at)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        """
    )
    db.execute(
        """
        CREATE TABLE IF NOT EXISTS notification_channel_sites (
            channel_id BIGINT UNSIGNED NOT NULL,
            site_id INT NOT NULL,
            PRIMARY KEY (channel_id, site_id),
            KEY idx_notification_channel_sites_site (site_id, channel_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
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
    if event_key in {"monitor_down", "incident_open", "tls_invalid"}:
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


def _truthy(value: Any) -> bool:
    return str(value or "").strip().lower() in {"1", "true", "yes", "on"}


def _subscriber_site_ids(db: Database, context: Dict[str, Any]) -> List[int]:
    site_ids: set[int] = set()
    values = context.get("site_ids")
    if isinstance(values, (list, tuple, set)):
        candidates = values
    else:
        candidates = str(values or "").replace(";", ",").split(",")
    for value in candidates:
        try:
            site_id = int(value)
        except (TypeError, ValueError):
            site_id = 0
        if site_id > 0:
            site_ids.add(site_id)
    try:
        direct_site_id = int(context.get("site_id") or 0)
    except (TypeError, ValueError):
        direct_site_id = 0
    if direct_site_id > 0:
        site_ids.add(direct_site_id)
    urls: set[str] = set()
    site_url = str(context.get("site_url") or "").strip()
    if site_url and site_url != "all services":
        urls.add(site_url)
    for value in str(context.get("sites") or "").split(","):
        url = value.strip()
        if url and url != "all services":
            urls.add(url)
    if urls:
        placeholders = ",".join(["%s"] * len(urls))
        for row in db.query_all(f"SELECT id FROM sites WHERE url IN ({placeholders})", tuple(sorted(urls))):
            site_id = int(row.get("id") or 0)
            if site_id > 0:
                site_ids.add(site_id)
    return sorted(site_ids)


def _subscriber_signature(cfg: Dict[str, str], subscriber_id: int, email: str) -> str:
    secret = str(cfg.get("subscriber_signing_secret") or "").encode("utf-8")
    if len(secret) < 32:
        return ""
    payload = f"unsubscribe:{subscriber_id}:{email.lower()}".encode("utf-8")
    return hmac.new(secret, payload, hashlib.sha256).hexdigest()


def subscriber_smtp_config(db: Database, cfg: Dict[str, str]) -> Dict[str, Any]:
    environment = {
        "host": str(cfg.get("email_smtp_host") or "").strip(),
        "port": cfg.get("email_smtp_port", "465"),
        "username": str(cfg.get("email_smtp_username") or "").strip(),
        "password": str(cfg.get("email_smtp_password") or ""),
        "encryption": str(cfg.get("email_smtp_encryption") or "ssl"),
        "from_name": str(cfg.get("email_from_name") or "Insight"),
        "from_email": str(cfg.get("email_smtp_username") or "").strip(),
    }
    if environment["host"] and environment["from_email"]:
        return environment
    try:
        channel_id = int(str(cfg.get("status_subscriber_smtp_channel_id") or "0").strip())
    except ValueError:
        channel_id = 0
    params: tuple[Any, ...] = ()
    selector = ""
    if channel_id > 0:
        selector = "AND id=%s"
        params = (channel_id,)
    row = db.query_one(
        f"SELECT config_ciphertext FROM notification_channels WHERE enabled=1 AND provider='smtp' AND last_status='success' AND last_test_at IS NOT NULL {selector} ORDER BY id LIMIT 1",
        params,
    )
    if row is None:
        return {}
    try:
        config = decrypt_config(str(row.get("config_ciphertext") or ""))
    except Exception:
        return {}
    return config if str(config.get("host") or "").strip() and str(config.get("from_email") or config.get("username") or "").strip() else {}


@lru_cache(maxsize=8)
def _subscriber_catalog(locale: str) -> Dict[str, str]:
    normalized = str(locale or "en").strip().lower()[:2]
    if not normalized.isalpha():
        normalized = "en"
    path = Path(__file__).resolve().parents[2] / "public" / "locales" / f"{normalized}.json"
    try:
        payload = json.loads(path.read_text(encoding="utf-8"))
    except Exception:
        payload = {}
    return {str(key): str(value) for key, value in payload.items()} if isinstance(payload, dict) else {}


def _subscriber_copy(locale: str, event_key: str, page_name: str, context: Dict[str, Any]) -> tuple[str, str]:
    catalog = _subscriber_catalog(locale)
    label = catalog.get(f"subscriptions.event.{event_key}", "Status update")
    sites = str(context.get("sites") or context.get("site_url") or catalog.get("subscriptions.event.services", "services")).strip()
    message = str(context.get("message") or context.get("summary") or "").strip()
    title = f"[{page_name}] {label}"
    body = catalog.get("subscriptions.event.line", "{label} for {sites}.").format(label=label, sites=sites)
    if message:
        body += f"\n\n{message}"
    body += "\n\n" + catalog.get("subscriptions.event.view", "View the status page: {page_url}")
    body += "\n\n" + catalog.get("subscriptions.event.unsubscribe", "Unsubscribe: {unsubscribe_url}")
    return title[:500], body


def _dispatch_subscribers(
    db: Database,
    cfg: Dict[str, str],
    event_key: str,
    context: Dict[str, Any],
    idempotency_key: str,
) -> Dict[str, Any]:
    allowed_events = {"incident_open", "incident_update", "incident_acknowledged", "incident_resolved", "maintenance_started", "maintenance_ended"}
    if event_key not in allowed_events or not _truthy(cfg.get("status_subscriptions_enabled", "1")) or not _truthy(context.get("notify_subscribers", True)):
        return {"targeted": 0, "sent": 0, "failed": 0}
    smtp_config = subscriber_smtp_config(db, cfg)
    if not smtp_config:
        return {"targeted": 0, "sent": 0, "failed": 0}
    site_ids = _subscriber_site_ids(db, context)
    params: List[Any] = []
    scope = ""
    if site_ids:
        placeholders = ",".join(["%s"] * len(site_ids))
        subscriber_placeholders = ",".join(["%s"] * len(site_ids))
        scope = f"""AND ((p.slug='default' AND NOT EXISTS (SELECT 1 FROM status_page_monitors configured WHERE configured.status_page_id=p.id)) OR EXISTS (SELECT 1 FROM status_page_monitors scoped WHERE scoped.status_page_id=p.id AND scoped.visible=1 AND scoped.site_id IN ({placeholders})))
        AND (NOT EXISTS (SELECT 1 FROM status_page_subscriber_sites selection WHERE selection.subscriber_id=subscriber.id) OR EXISTS (SELECT 1 FROM status_page_subscriber_sites selection WHERE selection.subscriber_id=subscriber.id AND selection.site_id IN ({subscriber_placeholders})))"""
        params.extend(site_ids)
        params.extend(site_ids)
    subscribers = db.query_all(
        f"""
        SELECT subscriber.id, subscriber.email, subscriber.locale, p.name AS page_name, p.slug, p.custom_domain
        FROM status_page_subscribers subscriber
        INNER JOIN status_pages p ON p.id=subscriber.status_page_id
        WHERE p.enabled=1 AND subscriber.confirmed_at IS NOT NULL AND subscriber.unsubscribed_at IS NULL
        {scope}
        ORDER BY subscriber.id
        """,
        tuple(params),
    )
    public_url = str(cfg.get("public_url") or "").rstrip("/")
    result = {"targeted": len(subscribers), "sent": 0, "failed": 0}
    source_key = idempotency_key.strip()
    if not source_key:
        identity = context.get("id") or context.get("incident_id") or context.get("maintenance_id") or context.get("update_id") or ""
        source_key = hashlib.sha256(
            json.dumps([event_key, identity, site_ids, context.get("message") or ""], ensure_ascii=False, sort_keys=True).encode("utf-8")
        ).hexdigest()
    for subscriber in subscribers:
        subscriber_id = int(subscriber.get("id") or 0)
        email = str(subscriber.get("email") or "").strip()
        delivery_key = hashlib.sha256(f"{event_key}|{source_key}".encode("utf-8")).hexdigest()
        if db.query_one(
            "SELECT id FROM status_page_subscriber_deliveries WHERE subscriber_id=%s AND idempotency_key=%s LIMIT 1",
            (subscriber_id, delivery_key),
        ):
            result["targeted"] -= 1
            continue
        custom_domain = str(subscriber.get("custom_domain") or "").strip()
        slug = str(subscriber.get("slug") or "default").strip()
        page_url = f"https://{custom_domain}" if custom_domain else public_url
        if not custom_domain and slug != "default" and page_url:
            page_url += "?page=" + slug
        signature = _subscriber_signature(cfg, subscriber_id, email)
        unsubscribe_url = f"{public_url}/api/subscribers.php?action=unsubscribe&id={subscriber_id}&signature={signature}" if public_url and signature else page_url
        title, body_template = _subscriber_copy(str(subscriber.get("locale") or "en"), event_key, str(subscriber.get("page_name") or "Insight"), context)
        body = body_template.format(page_url=page_url or "-", unsubscribe_url=unsubscribe_url or "-")
        sent, details = _send_smtp({**smtp_config, "to": email}, title, body)
        db.execute(
            "INSERT INTO status_page_subscriber_deliveries (subscriber_id,event_key,idempotency_key,status,error_message) VALUES (%s,%s,%s,%s,%s)",
            (subscriber_id, event_key, delivery_key, "sent" if sent else "failed", None if sent else str(details)[:255]),
        )
        result["sent" if sent else "failed"] += 1
    db.execute("DELETE FROM status_page_subscriber_deliveries WHERE attempted_at < DATE_SUB(NOW(), INTERVAL 180 DAY)")
    return result


def dispatch_event(
    db: Database,
    cfg: Dict[str, str],
    event_key: str,
    context: Dict[str, Any],
    *,
    force: bool = False,
    channel_ids: Iterable[int] | None = None,
    idempotency_key: str = "",
) -> Dict[str, Any]:
    if event_key not in EVENT_KEYS:
        return {"ok": False, "configured": 0, "sent": 0, "failed": 0, "error": "Unknown event."}
    ensure_notification_schema(db)
    params: List[Any] = []
    where = "enabled = 1"
    ids = [int(value) for value in (channel_ids or []) if int(value) > 0]
    if ids:
        where = "enabled = 1 AND id IN (" + ",".join(["%s"] * len(ids)) + ")"
        params.extend(ids)
    channels = db.query_all(
        f"SELECT id, name, provider, enabled, config_ciphertext, events_json, minimum_severity FROM notification_channels WHERE {where} ORDER BY id",
        tuple(params),
    )
    if not force and str(cfg.get("disable_notifications") or "1").strip().lower() in {"1", "true", "yes", "on"}:
        return {"ok": True, "disabled": True, "configured": len(channels), "targeted": 0, "sent": 0, "failed": 0, "subscriber_targeted": 0, "subscriber_sent": 0, "subscriber_failed": 0, "results": []}
    templates = _template_for_event(db, event_key)
    results = []
    sent_count = 0
    failed_count = 0
    targeted = 0
    severity_order = {"info": 0, "minor": 1, "major": 2, "critical": 3}
    default_severity = {
        "monitor_down": "major",
        "monitor_up": "info",
        "incident_open": "major",
        "incident_update": "info",
        "incident_acknowledged": "info",
        "incident_resolved": "info",
        "tls_expiring": "minor",
        "tls_invalid": "critical",
        "maintenance_started": "info",
        "maintenance_ended": "info",
    }.get(event_key, "info")
    event_severity = str(context.get("severity") or default_severity).strip().lower()
    context_site_ids = set(_subscriber_site_ids(db, context))
    for channel in channels:
        events = _events_from_value(channel.get("events_json"))
        if event_key != "test" and event_key not in events:
            continue
        minimum_severity = str(channel.get("minimum_severity") or "info").strip().lower()
        if event_key != "test" and severity_order.get(event_severity, 0) < severity_order.get(minimum_severity, 0):
            continue
        targeted += 1
        channel_id = int(channel.get("id") or 0)
        if context_site_ids:
            routes = db.query_all("SELECT site_id FROM notification_channel_sites WHERE channel_id = %s", (channel_id,))
            route_site_ids = {int(route.get("site_id") or 0) for route in routes if int(route.get("site_id") or 0) > 0}
            if route_site_ids and context_site_ids.isdisjoint(route_site_ids):
                targeted -= 1
                continue
        delivery_key = ""
        if idempotency_key:
            delivery_key = hashlib.sha256(f"{event_key}|{channel_id}|{idempotency_key}".encode("utf-8")).hexdigest()
            if db.query_one("SELECT id FROM notification_deliveries WHERE channel_id = %s AND idempotency_key = %s LIMIT 1", (channel_id, delivery_key)):
                continue
        try:
            config = decrypt_config(str(channel.get("config_ciphertext") or ""))
            result = send_channel({**channel, "config": config}, event_key, context, templates)
        except Exception as exc:
            result = {"ok": False, "title": "", "error": str(exc)[:255]}
        status = "sent" if result.get("ok") else "failed"
        error = "" if result.get("ok") else str(result.get("error") or "Delivery failed.")[:255]
        db.execute(
            "INSERT INTO notification_deliveries (channel_id, event_key, idempotency_key, status, title_rendered, error_message) VALUES (%s, %s, %s, %s, %s, %s)",
            (channel_id, event_key, delivery_key or None, status, str(result.get("title") or "")[:500], error or None),
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
    subscriber_result = _dispatch_subscribers(db, cfg, event_key, context, idempotency_key)
    return {
        "ok": failed_count == 0 and int(subscriber_result.get("failed") or 0) == 0,
        "configured": len(channels),
        "targeted": targeted,
        "sent": sent_count,
        "failed": failed_count,
        "subscriber_targeted": int(subscriber_result.get("targeted") or 0),
        "subscriber_sent": int(subscriber_result.get("sent") or 0),
        "subscriber_failed": int(subscriber_result.get("failed") or 0),
        "results": results,
    }
