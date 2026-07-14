from __future__ import annotations

import os
from pathlib import Path
from typing import Dict


def _load_env_file(path: Path) -> None:
    if not path.is_file():
        return
    for raw_line in path.read_text(encoding="utf-8").splitlines():
        line = raw_line.strip()
        if not line or line.startswith("#") or "=" not in line:
            continue
        key, value = line.split("=", 1)
        key = key.strip()
        value = value.strip()
        if key.startswith("export "):
            key = key[7:].strip()
        if not key or key in os.environ:
            continue
        if len(value) >= 2 and value[0] == value[-1] and value[0] in {"'", '"'}:
            value = value[1:-1]
        os.environ[key] = value


def _env(name: str, default: str = "", *legacy_names: str) -> str:
    for candidate in (name, *legacy_names):
        value = os.getenv(candidate)
        if value is not None:
            return value
    return default


def load_monitoring_config(root_dir: Path) -> Dict[str, str]:
    root = root_dir.resolve()
    project_root = root.parent if root.name == "monitoring" else root
    _load_env_file(project_root / ".env")
    return {
        "app_name": _env("INSIGHT_APP_NAME", "Insight"),
        "public_url": _env("INSIGHT_PUBLIC_URL", ""),
        "timezone": _env("INSIGHT_TIMEZONE", "Europe/Paris"),
        "servername": _env("INSIGHT_DB_HOST", "localhost", "MONITORING_DB_HOST"),
        "username": _env("INSIGHT_DB_USER", "", "MONITORING_DB_USER"),
        "password": _env("INSIGHT_DB_PASSWORD", "", "MONITORING_DB_PASSWORD"),
        "dbname": _env("INSIGHT_DB_NAME", "insight", "MONITORING_DB_NAME"),
        "port": _env("INSIGHT_DB_PORT", "3306", "MONITORING_DB_PORT"),
        "db_socket": _env("INSIGHT_DB_SOCKET", "", "MONITORING_DB_SOCKET"),
        "sms_user": _env("INSIGHT_SMS_USER", "", "MONITORING_SMS_USER"),
        "sms_password": _env("INSIGHT_SMS_PASSWORD", "", "MONITORING_SMS_PASSWORD"),
        "http_interval_sec": _env("INSIGHT_MONITOR_INTERVAL_SEC", "60", "MONITORING_HTTP_INTERVAL_SEC"),
        "icmp_interval_sec": _env("INSIGHT_MONITOR_INTERVAL_SEC", "60", "MONITORING_ICMP_INTERVAL_SEC"),
        "http_methods": _env("INSIGHT_HTTP_METHODS", "GET", "MONITORING_HTTP_METHODS"),
        "http_redirect_modes": _env("INSIGHT_HTTP_REDIRECT_MODES", "follow", "MONITORING_HTTP_REDIRECT_MODES"),
        "http_primary_method": _env("INSIGHT_HTTP_PRIMARY_METHOD", "GET", "MONITORING_HTTP_PRIMARY_METHOD"),
        "http_primary_redirect": _env("INSIGHT_HTTP_PRIMARY_REDIRECT", "follow", "MONITORING_HTTP_PRIMARY_REDIRECT"),
        "scheduler_tolerance_sec": _env("INSIGHT_SCHEDULER_TOLERANCE_SEC", "5", "MONITORING_SCHEDULER_TOLERANCE_SEC"),
        "scheduler_force_run": _env("INSIGHT_SCHEDULER_FORCE_RUN", "0", "MONITORING_SCHEDULER_FORCE_RUN"),
        "monitoring_concurrency": _env("INSIGHT_MONITORING_CONCURRENCY", "4", "MONITORING_CONCURRENCY"),
        "monitoring_concurrency_max": _env("INSIGHT_MONITORING_CONCURRENCY_MAX", "24", "MONITORING_CONCURRENCY_MAX"),
        "reinforced_monitoring_enabled": _env("INSIGHT_REINFORCED_MONITORING_ENABLED", "1"),
        "reinforced_monitoring_duration_sec": _env("INSIGHT_REINFORCED_MONITORING_DURATION_SEC", "900"),
        "reinforced_monitor_interval_sec": _env("INSIGHT_REINFORCED_MONITOR_INTERVAL_SEC", "10"),
        "aggregation_reprocess_hours": _env("INSIGHT_AGGREGATION_REPROCESS_HOURS", "2"),
        "probe_retention_days": _env("INSIGHT_PROBE_RETENTION_DAYS", "30"),
        "diagnostic_retention_days": _env("INSIGHT_DIAGNOSTIC_RETENTION_DAYS", "14"),
        "data_dir": _env("INSIGHT_DATA_DIR", "/var/lib/insight"),
        "diagnostics_network": _env("INSIGHT_DIAGNOSTICS_NETWORK", "1"),
        "hourly_retention_days": _env("INSIGHT_HOURLY_RETENTION_DAYS", "365"),
        "daily_retention_days": _env("INSIGHT_DAILY_RETENTION_DAYS", "730"),
        "tls_retention_days": _env("INSIGHT_TLS_RETENTION_DAYS", "365"),
        "retention_batch_size": _env("INSIGHT_RETENTION_BATCH_SIZE", "5000"),
        "email_smtp_host": _env("INSIGHT_EMAIL_SMTP_HOST", "", "MONITORING_EMAIL_SMTP_HOST"),
        "email_smtp_port": _env("INSIGHT_EMAIL_SMTP_PORT", "465", "MONITORING_EMAIL_SMTP_PORT"),
        "email_smtp_username": _env("INSIGHT_EMAIL_SMTP_USERNAME", "", "MONITORING_EMAIL_SMTP_USERNAME"),
        "email_smtp_password": _env("INSIGHT_EMAIL_SMTP_PASSWORD", "", "MONITORING_EMAIL_SMTP_PASSWORD"),
        "email_smtp_encryption": _env("INSIGHT_EMAIL_SMTP_ENCRYPTION", "ssl", "MONITORING_EMAIL_SMTP_ENCRYPTION"),
        "email_from_name": _env("INSIGHT_EMAIL_FROM_NAME", _env("INSIGHT_APP_NAME", "Insight")),
        "status_subscriptions_enabled": _env("INSIGHT_STATUS_SUBSCRIPTIONS_ENABLED", "0"),
        "status_subscriber_smtp_channel_id": _env("INSIGHT_STATUS_SUBSCRIBER_SMTP_CHANNEL_ID", ""),
        "subscriber_signing_secret": _env("INSIGHT_STATUS_SUBSCRIBER_SECRET", _env("INSIGHT_NOTIFICATION_ENCRYPTION_KEY", "")),
        "disable_notifications": _env("INSIGHT_DISABLE_NOTIFICATIONS", "1", "MONITORING_DISABLE_NOTIFICATIONS"),
    }
