from __future__ import annotations

import os
import re
import subprocess
from pathlib import Path
from typing import Dict


_PAIR_RE = re.compile(r"'([^']+)'\s*=>\s*'([^']*)'")
_SMTP_VAR_RE = re.compile(
    r"\$(smtp_host|smtp_port|smtp_username|smtp_password|smtp_encryption|from_name)\s*=\s*['\"]([^'\"]*)['\"]",
    re.IGNORECASE,
)


def _decode_php_config(raw: bytes) -> str:
    # Keep bytes lossless for passwords containing non-ASCII chars.
    for enc in ("utf-8", "cp1252", "latin-1"):
        try:
            return raw.decode(enc)
        except UnicodeDecodeError:
            continue
    return raw.decode("latin-1", errors="replace")


def _load_php_array_config_via_php(config_path: Path) -> Dict[str, str]:
    php_candidates = [
        os.getenv("PHP_BIN", "").strip(),
        "/usr/local/bin/php",
        "/usr/bin/php",
        "php",
    ]

    code = (
        '$cfg = @require $argv[1];'
        'if (!is_array($cfg)) { echo "{}"; exit(0); }'
        'echo json_encode($cfg, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);'
    )

    for php_bin in php_candidates:
        if not php_bin:
            continue
        try:
            completed = subprocess.run(
                [php_bin, "-r", code, str(config_path)],
                capture_output=True,
                text=True,
                encoding="utf-8",
                errors="replace",
                timeout=8,
                check=False,
            )
        except Exception:
            continue

        raw = (completed.stdout or "").strip()
        if not raw:
            continue

        parsed = None
        try:
            import json

            parsed = json.loads(raw)
        except Exception:
            # Some hosts can prepend notices; try last non-empty line.
            lines = [line.strip() for line in raw.splitlines() if line.strip()]
            for line in reversed(lines):
                try:
                    parsed = json.loads(line)
                    break
                except Exception:
                    continue

        if isinstance(parsed, dict):
            return {str(k): str(v) for k, v in parsed.items()}

    return {}


def _load_php_mysqli_default_socket() -> str:
    php_candidates = [
        os.getenv("PHP_BIN", "").strip(),
        "/usr/local/bin/php",
        "/usr/bin/php",
        "php",
    ]
    code = 'echo (string)ini_get("mysqli.default_socket");'
    for php_bin in php_candidates:
        if not php_bin:
            continue
        try:
            completed = subprocess.run(
                [php_bin, "-r", code],
                capture_output=True,
                text=True,
                encoding="utf-8",
                errors="replace",
                timeout=6,
                check=False,
            )
        except Exception:
            continue
        value = (completed.stdout or "").strip()
        if value:
            return value
    return ""


def load_php_array_config(config_path: Path) -> Dict[str, str]:
    php_cfg = _load_php_array_config_via_php(config_path)
    if php_cfg:
        return php_cfg

    data = _decode_php_config(config_path.read_bytes())
    out: Dict[str, str] = {}
    for key, value in _PAIR_RE.findall(data):
        out[key] = value
    return out


def load_auth_smtp_config(root_dir: Path) -> Dict[str, str]:
    smtp_path = root_dir / "config" / "smtp.php"
    if not smtp_path.is_file():
        return {}

    try:
        raw = smtp_path.read_bytes()
    except Exception:
        return {}

    data = _decode_php_config(raw)
    out: Dict[str, str] = {}
    for key, value in _SMTP_VAR_RE.findall(data):
        out[str(key).lower()] = str(value)
    return out


def load_monitoring_config(root_dir: Path) -> Dict[str, str]:
    config_path = root_dir / "config" / "config.php"
    cfg = load_php_array_config(config_path)
    smtp_cfg = load_auth_smtp_config(root_dir)

    # Allow secure overrides via environment.
    cfg["servername"] = os.getenv("INSIGHT_DB_HOST", os.getenv("MONITORING_DB_HOST", cfg.get("servername", "localhost")))
    cfg["username"] = os.getenv("INSIGHT_DB_USER", os.getenv("MONITORING_DB_USER", cfg.get("username", "")))
    cfg["password"] = os.getenv("INSIGHT_DB_PASSWORD", os.getenv("MONITORING_DB_PASSWORD", cfg.get("password", "")))
    cfg["dbname"] = os.getenv("INSIGHT_DB_NAME", os.getenv("MONITORING_DB_NAME", cfg.get("dbname", "")))
    cfg["port"] = os.getenv("INSIGHT_DB_PORT", os.getenv("MONITORING_DB_PORT", cfg.get("port", "3306")))
    cfg["db_socket"] = os.getenv("INSIGHT_DB_SOCKET", os.getenv("MONITORING_DB_SOCKET", cfg.get("db_socket", "")))
    if not cfg["db_socket"]:
        cfg["db_socket"] = _load_php_mysqli_default_socket()
    cfg["sms_user"] = os.getenv("INSIGHT_SMS_USER", os.getenv("MONITORING_SMS_USER", cfg.get("sms_user", "")))
    cfg["sms_password"] = os.getenv("INSIGHT_SMS_PASSWORD", os.getenv("MONITORING_SMS_PASSWORD", cfg.get("sms_password", "")))
    cfg["table_suffix"] = os.getenv("MONITORING_TABLE_SUFFIX", cfg.get("table_suffix", ""))
    cfg["shadow_mode"] = os.getenv("MONITORING_SHADOW_MODE", cfg.get("shadow_mode", "0"))
    cfg["http_interval_sec"] = os.getenv("INSIGHT_MONITOR_INTERVAL_SEC", os.getenv("MONITORING_HTTP_INTERVAL_SEC", cfg.get("http_interval_sec", "60")))
    cfg["icmp_interval_sec"] = os.getenv("MONITORING_ICMP_INTERVAL_SEC", cfg.get("icmp_interval_sec", "60"))
    cfg["http_methods"] = os.getenv(
        "MONITORING_HTTP_METHODS",
        cfg.get("http_methods", "GET,POST,PUT,HEAD,DELETE,PATCH,OPTIONS"),
    )
    cfg["http_redirect_modes"] = os.getenv(
        "MONITORING_HTTP_REDIRECT_MODES",
        cfg.get("http_redirect_modes", "follow,no_follow"),
    )
    cfg["http_primary_method"] = os.getenv("MONITORING_HTTP_PRIMARY_METHOD", cfg.get("http_primary_method", "GET"))
    cfg["http_primary_redirect"] = os.getenv("MONITORING_HTTP_PRIMARY_REDIRECT", cfg.get("http_primary_redirect", "follow"))
    cfg["scheduler_tolerance_sec"] = os.getenv("MONITORING_SCHEDULER_TOLERANCE_SEC", cfg.get("scheduler_tolerance_sec", "5"))
    cfg["scheduler_force_run"] = os.getenv("MONITORING_SCHEDULER_FORCE_RUN", cfg.get("scheduler_force_run", "0"))
    cfg["monitoring_concurrency"] = os.getenv("INSIGHT_MONITORING_CONCURRENCY", os.getenv("MONITORING_CONCURRENCY", cfg.get("monitoring_concurrency", "4")))
    cfg["monitoring_concurrency_max"] = os.getenv("MONITORING_CONCURRENCY_MAX", cfg.get("monitoring_concurrency_max", "24"))
    cfg["monitoring_escalation_max_age_minutes"] = os.getenv(
        "MONITORING_ESCALATION_MAX_AGE_MINUTES",
        cfg.get("monitoring_escalation_max_age_minutes", "360"),
    )
    cfg["monitoring_escalation_max_notifications_per_run"] = os.getenv(
        "MONITORING_ESCALATION_MAX_NOTIFICATIONS_PER_RUN",
        cfg.get("monitoring_escalation_max_notifications_per_run", "20"),
    )
    cfg["aggregation_reprocess_hours"] = os.getenv(
        "INSIGHT_AGGREGATION_REPROCESS_HOURS",
        cfg.get("aggregation_reprocess_hours", "2"),
    )
    cfg["probe_retention_days"] = os.getenv("INSIGHT_PROBE_RETENTION_DAYS", cfg.get("probe_retention_days", "30"))
    cfg["hourly_retention_days"] = os.getenv("INSIGHT_HOURLY_RETENTION_DAYS", cfg.get("hourly_retention_days", "365"))
    cfg["daily_retention_days"] = os.getenv("INSIGHT_DAILY_RETENTION_DAYS", cfg.get("daily_retention_days", "730"))
    cfg["tls_retention_days"] = os.getenv("INSIGHT_TLS_RETENTION_DAYS", cfg.get("tls_retention_days", "365"))
    cfg["retention_batch_size"] = os.getenv("INSIGHT_RETENTION_BATCH_SIZE", cfg.get("retention_batch_size", "5000"))
    cfg["email_smtp_host"] = os.getenv("INSIGHT_EMAIL_SMTP_HOST", os.getenv("MONITORING_EMAIL_SMTP_HOST", smtp_cfg.get("smtp_host", "")))
    cfg["email_smtp_port"] = os.getenv("INSIGHT_EMAIL_SMTP_PORT", os.getenv("MONITORING_EMAIL_SMTP_PORT", smtp_cfg.get("smtp_port", "465")))
    cfg["email_smtp_username"] = os.getenv("INSIGHT_EMAIL_SMTP_USERNAME", os.getenv("MONITORING_EMAIL_SMTP_USERNAME", smtp_cfg.get("smtp_username", "")))
    cfg["email_smtp_password"] = os.getenv("INSIGHT_EMAIL_SMTP_PASSWORD", os.getenv("MONITORING_EMAIL_SMTP_PASSWORD", smtp_cfg.get("smtp_password", "")))
    cfg["email_smtp_encryption"] = os.getenv("INSIGHT_EMAIL_SMTP_ENCRYPTION", os.getenv("MONITORING_EMAIL_SMTP_ENCRYPTION", smtp_cfg.get("smtp_encryption", "ssl")))
    cfg["email_from_name"] = os.getenv("INSIGHT_EMAIL_FROM_NAME", os.getenv("MONITORING_EMAIL_FROM_NAME", smtp_cfg.get("from_name", "Insight")))
    if "INSIGHT_DISABLE_NOTIFICATIONS" in os.environ:
        cfg["disable_notifications"] = os.getenv("INSIGHT_DISABLE_NOTIFICATIONS", "1")
    elif "MONITORING_DISABLE_NOTIFICATIONS" in os.environ:
        cfg["disable_notifications"] = os.getenv("MONITORING_DISABLE_NOTIFICATIONS", "0")
    else:
        cfg["disable_notifications"] = cfg.get("disable_notifications", "1")

    return cfg
