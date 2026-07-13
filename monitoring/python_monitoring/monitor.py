from __future__ import annotations

import http.cookiejar
import logging
import os
import socket
import ssl
import subprocess
import tempfile
import time
from concurrent.futures import ThreadPoolExecutor, as_completed
from datetime import datetime
from pathlib import Path
from typing import Any, Dict, List, Optional, Set, Tuple
from urllib.error import HTTPError, URLError
from urllib.parse import urlparse
from urllib.request import HTTPCookieProcessor, HTTPRedirectHandler, Request, build_opener

from .alerts import send_email_smtp, send_sms
from .db import Database
from .notifications import dispatch_event, has_notification_channels
from .table_names import is_shadow_mode, is_truthy, table_name, table_sql


SUPPORTED_INTERVALS_SEC: Tuple[int, ...] = (60, 120, 180, 300, 600, 1800, 21600, 43200, 86400)
SUPPORTED_HTTP_METHODS: Tuple[str, ...] = ("GET", "POST", "PUT", "HEAD", "DELETE", "PATCH", "OPTIONS")
DEFAULT_ESCALATION_POLICY: Tuple[Dict[str, Any], ...] = (
    {
        "step_key": "email",
        "label": "Email",
        "channel": "email",
        "delay_minutes": 1,
        "enabled": 1,
        "is_placeholder": 0,
        "sort_order": 1,
    },
    {
        "step_key": "sms",
        "label": "SMS",
        "channel": "sms",
        "delay_minutes": 3,
        "enabled": 1,
        "is_placeholder": 0,
        "sort_order": 2,
    },
    {
        "step_key": "call",
        "label": "Appel",
        "channel": "call",
        "delay_minutes": 10,
        "enabled": 1,
        "is_placeholder": 1,
        "sort_order": 3,
    },
)


class NoRedirectHandler(HTTPRedirectHandler):
    def redirect_request(self, req: Request, fp: Any, code: int, msg: str, headers: Any, newurl: str) -> Optional[Request]:
        return None


def _parse_int(value: Any, default: int) -> int:
    try:
        return int(str(value).strip())
    except Exception:
        return int(default)


def _normalize_interval(value: Any, default: int) -> int:
    interval = _parse_int(value, default)
    if interval in SUPPORTED_INTERVALS_SEC:
        return interval
    return default


def _parse_http_methods(raw: Any) -> List[str]:
    methods: List[str] = []
    tokens = str(raw or "").replace(";", ",").split(",")
    seen: Set[str] = set()
    for token in tokens:
        method = token.strip().upper()
        if not method or method not in SUPPORTED_HTTP_METHODS or method in seen:
            continue
        seen.add(method)
        methods.append(method)
    if methods:
        return methods
    return list(SUPPORTED_HTTP_METHODS)


def _monitoring_source_node(cfg: Dict[str, str]) -> str:
    configured = str(cfg.get("monitoring_replica_source_node", "") or "").strip()
    if configured:
        return configured
    try:
        host = socket.gethostname().strip()
    except Exception:
        host = ""
    return host or "monitoring"


def _parse_redirect_modes(raw: Any) -> List[str]:
    modes: List[str] = []
    seen: Set[str] = set()
    tokens = str(raw or "").replace(";", ",").split(",")
    for token in tokens:
        value = token.strip().lower()
        normalized = ""
        if value in {"follow", "on", "true", "yes", "1"}:
            normalized = "follow"
        elif value in {"no_follow", "nofollow", "strict", "off", "false", "no", "0"}:
            normalized = "no_follow"
        if not normalized or normalized in seen:
            continue
        seen.add(normalized)
        modes.append(normalized)
    if modes:
        return modes
    return ["follow", "no_follow"]


def _redirect_mode_to_bool(mode: str) -> bool:
    return str(mode or "").strip().lower() == "follow"


def _is_interval_due(now_ts: int, interval_sec: int, tolerance_sec: int, force_run: bool) -> bool:
    if force_run:
        return True
    if interval_sec <= 0:
        return False
    mod = now_ts % interval_sec
    return mod <= tolerance_sec or (interval_sec - mod) <= tolerance_sec


def _http_code_is_online(http_code: int) -> bool:
    if http_code <= 0:
        return False
    if http_code >= 500:
        return False
    return True


def _setup_logger(log_dir: Path, logger_key: str = "") -> logging.Logger:
    log_dir.mkdir(parents=True, exist_ok=True)
    logger_name = "python_monitoring" + (f".{logger_key}" if logger_key else "")
    logger = logging.getLogger(logger_name)
    if logger.handlers:
        return logger

    logger.setLevel(logging.INFO)
    suffix = f"_{logger_key}" if logger_key else ""
    file_handler = logging.FileHandler(log_dir / f"worker{suffix}.log", encoding="utf-8")
    formatter = logging.Formatter("[%(asctime)s] %(levelname)s: %(message)s")
    file_handler.setFormatter(formatter)
    logger.addHandler(file_handler)
    return logger


def _parse_datetime(value: Any) -> Optional[datetime]:
    if value is None:
        return None
    if isinstance(value, datetime):
        return value

    raw = str(value).strip()
    if not raw:
        return None

    for fmt in ("%Y-%m-%d %H:%M:%S", "%Y-%m-%dT%H:%M:%S"):
        try:
            return datetime.strptime(raw[:19], fmt)
        except ValueError:
            continue
    return None


def ensure_ssl_checks_table(db: Database, logger: logging.Logger, ssl_table: str) -> None:
    ssl_table_sql = f"`{ssl_table}`"
    db.execute(
        f"""
        CREATE TABLE IF NOT EXISTS {ssl_table_sql} (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            site_id INT NOT NULL,
            host VARCHAR(255) NOT NULL,
            port INT NOT NULL DEFAULT 443,
            checked_by VARCHAR(3) NOT NULL DEFAULT 'pyt',
            is_valid TINYINT(1) NULL,
            valid_from DATETIME NULL,
            valid_to DATETIME NULL,
            days_remaining INT NULL,
            issuer_name VARCHAR(255) NULL,
            issuer_cn VARCHAR(255) NULL,
            subject_cn VARCHAR(255) NULL,
            san TEXT NULL,
            tls_version VARCHAR(32) NULL,
            cipher_name VARCHAR(64) NULL,
            error_message VARCHAR(255) NULL,
            checked_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            INDEX idx_ssl_site_checked (site_id, checked_at),
            INDEX idx_ssl_valid_to (valid_to)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
        """
    )

    columns = {
        "site_id": "INT NOT NULL",
        "host": "VARCHAR(255) NOT NULL",
        "port": "INT NOT NULL DEFAULT 443",
        "checked_by": "VARCHAR(3) NOT NULL DEFAULT 'pyt'",
        "is_valid": "TINYINT(1) NULL",
        "valid_from": "DATETIME NULL",
        "valid_to": "DATETIME NULL",
        "days_remaining": "INT NULL",
        "issuer_name": "VARCHAR(255) NULL",
        "issuer_cn": "VARCHAR(255) NULL",
        "subject_cn": "VARCHAR(255) NULL",
        "san": "TEXT NULL",
        "tls_version": "VARCHAR(32) NULL",
        "cipher_name": "VARCHAR(64) NULL",
        "error_message": "VARCHAR(255) NULL",
        "checked_at": "DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP",
    }

    for column, definition in columns.items():
        exists = db.query_one(
            f"""
            SELECT 1 AS present
            FROM INFORMATION_SCHEMA.COLUMNS
            WHERE TABLE_SCHEMA = DATABASE()
              AND TABLE_NAME = %s
              AND COLUMN_NAME = %s
            LIMIT 1
            """,
            (ssl_table, column),
        )
        if exists:
            continue

        try:
            db.execute(f"ALTER TABLE {ssl_table_sql} ADD COLUMN {column} {definition}")
        except Exception as exc:
            logger.error("Unable to add %s.%s: %s", ssl_table, column, exc)


def ensure_probes_checked_by_column(db: Database, logger: logging.Logger, probes_table: str) -> None:
    probes_table_sql = f"`{probes_table}`"
    definitions = {
        "checked_by": "VARCHAR(3) NOT NULL DEFAULT 'pyt'",
        "source_node": "VARCHAR(64) NULL",
        "source_probe_id": "BIGINT UNSIGNED NULL",
    }
    for column, definition in definitions.items():
        exists = db.query_one(
            """
            SELECT 1 AS present
            FROM INFORMATION_SCHEMA.COLUMNS
            WHERE TABLE_SCHEMA = DATABASE()
              AND TABLE_NAME = %s
              AND COLUMN_NAME = %s
            LIMIT 1
            """,
            (probes_table, column),
        )
        if exists:
            continue
        try:
            db.execute(f"ALTER TABLE {probes_table_sql} ADD COLUMN {column} {definition}")
        except Exception as exc:
            logger.error("Unable to add %s.%s: %s", probes_table, column, exc)


def ensure_sites_runtime_columns(db: Database, logger: logging.Logger) -> None:
    definitions = {
        "probe_interval_sec": "INT NOT NULL DEFAULT 60",
        "calc_method": "VARCHAR(24) NOT NULL DEFAULT 'inherit'",
        "http_methods": "VARCHAR(128) NOT NULL DEFAULT 'GET,POST,PUT,HEAD,DELETE,PATCH,OPTIONS'",
        "http_redirect_modes": "VARCHAR(32) NOT NULL DEFAULT 'follow,no_follow'",
        "http_primary_method": "VARCHAR(16) NOT NULL DEFAULT 'GET'",
        "http_primary_redirect": "VARCHAR(16) NOT NULL DEFAULT 'follow'",
    }
    for column, ddl in definitions.items():
        exists = db.query_one(
            """
            SELECT 1 AS present
            FROM INFORMATION_SCHEMA.COLUMNS
            WHERE TABLE_SCHEMA = DATABASE()
              AND TABLE_NAME = 'sites'
              AND COLUMN_NAME = %s
            LIMIT 1
            """,
            (column,),
        )
        if exists:
            continue
        try:
            db.execute(f"ALTER TABLE `sites` ADD COLUMN {column} {ddl}")
        except Exception as exc:
            logger.error("Unable to add sites.%s: %s", column, exc)


def ensure_scheduled_maintenances_table(db: Database) -> None:
    db.execute(
        """
        CREATE TABLE IF NOT EXISTS scheduled_maintenances (
            id INT NOT NULL AUTO_INCREMENT,
            site_id INT NULL,
            title VARCHAR(160) NOT NULL,
            description TEXT NULL,
            starts_at DATETIME NOT NULL,
            ends_at DATETIME NOT NULL,
            status ENUM('planned','cancelled','completed') NOT NULL DEFAULT 'planned',
            notify_public TINYINT(1) NOT NULL DEFAULT 1,
            created_by_user_id INT NULL,
            created_by_name VARCHAR(140) NULL,
            cancelled_at DATETIME NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            KEY idx_maintenance_site (site_id),
            KEY idx_maintenance_status (status),
            KEY idx_maintenance_dates (starts_at, ends_at)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
        """
    )


def ensure_escalation_tables(db: Database) -> None:
    db.execute(
        """
        CREATE TABLE IF NOT EXISTS monitoring_escalation_policy (
            step_key VARCHAR(32) NOT NULL,
            label VARCHAR(80) NOT NULL,
            channel ENUM('email','sms','call') NOT NULL DEFAULT 'email',
            delay_minutes INT NOT NULL DEFAULT 1,
            enabled TINYINT(1) NOT NULL DEFAULT 1,
            is_placeholder TINYINT(1) NOT NULL DEFAULT 0,
            sort_order INT NOT NULL DEFAULT 1,
            updated_by_user_id INT NULL,
            updated_by_name VARCHAR(140) NULL,
            updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (step_key),
            KEY idx_monitoring_escalation_policy_sort (sort_order, delay_minutes)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
        """
    )
    db.execute(
        """
        CREATE TABLE IF NOT EXISTS monitoring_escalation_events (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            incident_id INT NOT NULL,
            step_key VARCHAR(32) NOT NULL,
            channel ENUM('email','sms','call') NOT NULL,
            delay_minutes INT NOT NULL DEFAULT 0,
            status ENUM('pending','sent','failed','skipped','placeholder') NOT NULL DEFAULT 'pending',
            details VARCHAR(255) NULL,
            triggered_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            UNIQUE KEY uniq_monitoring_escalation_incident_step (incident_id, step_key),
            KEY idx_monitoring_escalation_incident (incident_id),
            KEY idx_monitoring_escalation_triggered_at (triggered_at)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
        """
    )
    uniq_rows: List[Dict[str, Any]] = []
    try:
        uniq_rows = db.query_all(
            """
            SHOW INDEX FROM monitoring_escalation_events
            WHERE Key_name = 'uniq_monitoring_escalation_incident_step'
            """
        )
    except Exception:
        uniq_rows = []
    if not uniq_rows:
        try:
            db.execute(
                """
                DELETE e1 FROM monitoring_escalation_events e1
                INNER JOIN monitoring_escalation_events e2
                  ON e1.incident_id = e2.incident_id
                 AND e1.step_key = e2.step_key
                 AND e1.id > e2.id
                """
            )
        except Exception:
            pass
        try:
            db.execute(
                """
                ALTER TABLE monitoring_escalation_events
                ADD UNIQUE KEY uniq_monitoring_escalation_incident_step (incident_id, step_key)
                """
            )
        except Exception:
            pass

    for step in DEFAULT_ESCALATION_POLICY:
        db.execute(
            """
            INSERT INTO monitoring_escalation_policy
                (step_key, label, channel, delay_minutes, enabled, is_placeholder, sort_order, updated_by_name, updated_at)
            VALUES (%s, %s, %s, %s, %s, %s, %s, 'System', NOW())
            ON DUPLICATE KEY UPDATE step_key = step_key
            """,
            (
                str(step.get("step_key") or ""),
                str(step.get("label") or ""),
                str(step.get("channel") or "email"),
                int(step.get("delay_minutes") or 1),
                int(step.get("enabled") or 0),
                int(step.get("is_placeholder") or 0),
                int(step.get("sort_order") or 1),
            ),
        )


def _normalize_escalation_step_key(raw: Any, fallback: str = "") -> str:
    value = str(raw or "").strip().lower()
    normalized = "".join(ch if (ch.isalnum() or ch in {"_", "-"}) else "_" for ch in value).strip("_-")
    if not normalized:
        fallback_value = str(fallback or "").strip().lower()
        normalized = "".join(ch if (ch.isalnum() or ch in {"_", "-"}) else "_" for ch in fallback_value).strip("_-")
    if not normalized:
        normalized = "step"
    return normalized[:32]


def _normalize_escalation_channel(raw: Any) -> str:
    channel = str(raw or "").strip().lower()
    if channel in {"email", "sms", "call"}:
        return channel
    return "email"


def _normalize_escalation_delay(raw: Any, default: int = 1) -> int:
    delay = _parse_int(raw, default)
    if delay < 0:
        return 0
    if delay > 1440:
        return 1440
    return delay


def _normalize_escalation_policy_rows(rows: List[Dict[str, Any]]) -> List[Dict[str, Any]]:
    normalized: List[Dict[str, Any]] = []
    seen: Set[str] = set()
    for index, row in enumerate(rows):
        key = _normalize_escalation_step_key(row.get("step_key"), f"step_{index + 1}")
        if key in seen:
            continue
        seen.add(key)
        channel = _normalize_escalation_channel(row.get("channel"))
        label = str(row.get("label") or "").strip() or channel.upper()
        normalized.append(
            {
                "step_key": key,
                "label": label[:80],
                "channel": channel,
                "delay_minutes": _normalize_escalation_delay(row.get("delay_minutes"), 1),
                "enabled": int(row.get("enabled") or 0) == 1,
                "is_placeholder": (channel == "call") or int(row.get("is_placeholder") or 0) == 1,
                "sort_order": max(1, _parse_int(row.get("sort_order"), index + 1)),
            }
        )
    normalized.sort(key=lambda step: (int(step["sort_order"]), int(step["delay_minutes"]), str(step["step_key"])))
    for idx, step in enumerate(normalized):
        step["sort_order"] = idx + 1
    return normalized


def load_escalation_policy(db: Database) -> List[Dict[str, Any]]:
    rows = db.query_all(
        """
        SELECT step_key, label, channel, delay_minutes, enabled, is_placeholder, sort_order
        FROM monitoring_escalation_policy
        ORDER BY sort_order ASC, delay_minutes ASC, step_key ASC
        """
    )
    if not rows:
        rows = [dict(item) for item in DEFAULT_ESCALATION_POLICY]
    return _normalize_escalation_policy_rows(rows)


def _format_escalation_duration(total_seconds: int) -> str:
    safe_seconds = max(0, int(total_seconds))
    days = safe_seconds // 86400
    remainder = safe_seconds % 86400
    hours = remainder // 3600
    minutes = (safe_seconds % 3600) // 60
    seconds = safe_seconds % 60
    if days > 0:
        return f"{days}j {hours:02d}:{minutes:02d}:{seconds:02d}"
    return f"{hours:02d}:{minutes:02d}:{seconds:02d}"


def _escalation_incident_ref(incident_id: int, site_url: str) -> str:
    host = _extract_host(site_url)
    base = f"INC-{incident_id:06d}"
    if host:
        return f"{base} · {host}"
    if site_url.strip():
        return f"{base} · {site_url.strip()}"
    return base


def _escalation_subject(label: str, incident_ref: str) -> str:
    safe_label = " ".join(str(label or "").split()).strip() or "Escalation"
    safe_ref = " ".join(str(incident_ref or "").split()).strip()
    subject = f"Monitoring escalation - {safe_label}"
    if safe_ref:
        subject += f" · {safe_ref}"
    return subject[:160]


def _escalation_message(label: str, incident_ref: str, elapsed_seconds: int, compact: bool) -> str:
    safe_label = " ".join(str(label or "").split()).strip() or "Escalation"
    safe_ref = " ".join(str(incident_ref or "").split()).strip()
    duration = _format_escalation_duration(elapsed_seconds)
    if compact:
        parts = [f"Escalation {safe_label}.", f"T+{duration}."]
        if safe_ref:
            parts.insert(1, f"Incident: {safe_ref}.")
        parts.append("Monitoring engine.")
        return " ".join(parts)[:480]
    lines = [f"Escalation tier {safe_label} was triggered."]
    if safe_ref:
        lines.append(f"Incident context: {safe_ref}.")
    lines.append(f"Elapsed time since opening: {duration}.")
    lines.append(f"Horodatage : {datetime.now().strftime('%d/%m/%Y %H:%M:%S')}.")
    lines.append("Source: automatic monitoring engine.")
    return "\n".join(lines)

def get_active_maintenance_scope(db: Database, site_ids: List[int]) -> tuple[bool, Set[int]]:
    ids = sorted({int(site_id) for site_id in site_ids if int(site_id) > 0})
    if not ids:
        return (False, set())

    placeholders = ", ".join(["%s"] * len(ids))
    rows = db.query_all(
        f"""
        SELECT site_id
        FROM scheduled_maintenances
        WHERE status <> 'cancelled'
          AND starts_at <= NOW()
          AND ends_at >= NOW()
          AND (site_id IS NULL OR site_id IN ({placeholders}))
        """,
        tuple(ids),
    )

    global_active = False
    specific_sites: Set[int] = set()
    for row in rows:
        raw_site_id = row.get("site_id")
        if raw_site_id is None:
            global_active = True
            continue
        try:
            sid = int(raw_site_id)
        except Exception:
            sid = 0
        if sid > 0:
            specific_sites.add(sid)
    return (global_active, specific_sites)


def _normalize_url_for_ssl(url: str) -> Tuple[Optional[str], Optional[str], Optional[int]]:
    value = (url or "").strip()
    if not value:
        return (None, None, None)

    parsed = urlparse(value)
    if not parsed.netloc:
        parsed = urlparse("https://" + value.lstrip("/"))

    host = (parsed.hostname or "").strip().lower()
    if not host:
        return (None, None, None)

    scheme = (parsed.scheme or "https").strip().lower()
    port = int(parsed.port or 443)
    return (scheme, host, port)


def _format_ssl_datetime(epoch: Optional[int]) -> Optional[str]:
    if epoch is None:
        return None
    try:
        return datetime.fromtimestamp(int(epoch)).strftime("%Y-%m-%d %H:%M:%S")
    except Exception:
        return None


def _subject_to_map(entries: Any) -> Dict[str, str]:
    out: Dict[str, str] = {}
    if not isinstance(entries, tuple):
        return out

    for item in entries:
        if not isinstance(item, tuple):
            continue
        for pair in item:
            if isinstance(pair, tuple) and len(pair) == 2:
                key = str(pair[0] or "").strip()
                value = str(pair[1] or "").strip()
                if key and value:
                    out[key] = value
    return out


def _decode_cert_from_der(der_bytes: bytes) -> Dict[str, Any]:
    if not der_bytes:
        return {}

    try:
        pem = ssl.DER_cert_to_PEM_cert(der_bytes)
        ssl_module = getattr(ssl, "_ssl", None)
        if ssl_module is None or not hasattr(ssl_module, "_test_decode_cert"):
            return {}

        with tempfile.NamedTemporaryFile(mode="w", suffix=".pem", encoding="ascii", delete=True) as tmp:
            tmp.write(pem)
            tmp.flush()
            decoded = ssl_module._test_decode_cert(tmp.name)  # type: ignore[attr-defined]
            if isinstance(decoded, dict):
                return decoded
    except Exception:
        return {}

    return {}


def check_ssl_certificate(url: str) -> Dict[str, Any]:
    scheme, host, port = _normalize_url_for_ssl(url)
    if not host:
        return {
            "host": None,
            "port": None,
            "is_valid": None,
            "valid_from": None,
            "valid_to": None,
            "days_remaining": None,
            "issuer_name": None,
            "issuer_cn": None,
            "subject_cn": None,
            "san": None,
            "tls_version": None,
            "cipher_name": None,
            "error_message": "invalid_host",
        }

    tls_port = port if scheme == "https" else 443

    try:
        context = ssl.create_default_context()
        context.check_hostname = False
        context.verify_mode = ssl.CERT_NONE

        with socket.create_connection((host, tls_port), timeout=10) as sock:
            with context.wrap_socket(sock, server_hostname=host) as tls_sock:
                cert = tls_sock.getpeercert() or {}
                if not cert:
                    cert_der = tls_sock.getpeercert(binary_form=True)
                    if isinstance(cert_der, (bytes, bytearray)):
                        cert = _decode_cert_from_der(bytes(cert_der))
                tls_version = tls_sock.version()
                cipher = tls_sock.cipher() or (None, None, None)
    except Exception as exc:
        return {
            "host": host,
            "port": tls_port,
            "is_valid": 0,
            "valid_from": None,
            "valid_to": None,
            "days_remaining": None,
            "issuer_name": None,
            "issuer_cn": None,
            "subject_cn": None,
            "san": None,
            "tls_version": None,
            "cipher_name": None,
            "error_message": str(exc)[:255] or "ssl_connect_failed",
        }

    if not cert:
        return {
            "host": host,
            "port": tls_port,
            "is_valid": 0,
            "valid_from": None,
            "valid_to": None,
            "days_remaining": None,
            "issuer_name": None,
            "issuer_cn": None,
            "subject_cn": None,
            "san": None,
            "tls_version": tls_version,
            "cipher_name": cipher[0],
            "error_message": "peer_certificate_missing",
        }

    issuer_map = _subject_to_map(cert.get("issuer"))
    subject_map = _subject_to_map(cert.get("subject"))

    issuer_cn = issuer_map.get("commonName")
    issuer_org = issuer_map.get("organizationName")
    subject_cn = subject_map.get("commonName")

    not_before_epoch: Optional[int] = None
    not_after_epoch: Optional[int] = None

    try:
        if cert.get("notBefore"):
            not_before_epoch = int(ssl.cert_time_to_seconds(str(cert["notBefore"])))
    except Exception:
        not_before_epoch = None

    try:
        if cert.get("notAfter"):
            not_after_epoch = int(ssl.cert_time_to_seconds(str(cert["notAfter"])))
    except Exception:
        not_after_epoch = None

    days_remaining: Optional[int] = None
    if not_after_epoch is not None:
        days_remaining = int((not_after_epoch - int(time.time())) // 86400)

    sans = cert.get("subjectAltName")
    san_string = None
    if isinstance(sans, (list, tuple)):
        parts = []
        for item in sans:
            if isinstance(item, tuple) and len(item) == 2:
                parts.append(f"{item[0]}:{item[1]}")
        if parts:
            san_string = ", ".join(parts)

    issuer_name = issuer_org or issuer_cn
    if not issuer_name and issuer_map:
        issuer_name = ", ".join(f"{k}={v}" for k, v in issuer_map.items())

    return {
        "host": host,
        "port": tls_port,
        "is_valid": 1 if (days_remaining is not None and days_remaining >= 0) else 0,
        "valid_from": _format_ssl_datetime(not_before_epoch),
        "valid_to": _format_ssl_datetime(not_after_epoch),
        "days_remaining": days_remaining,
        "issuer_name": issuer_name,
        "issuer_cn": issuer_cn,
        "subject_cn": subject_cn,
        "san": san_string,
        "tls_version": tls_version,
        "cipher_name": cipher[0],
        "error_message": None,
    }


def insert_ssl_check(db: Database, site_id: int, ssl_result: Dict[str, Any], ssl_table_sql: str) -> None:
    if not ssl_result.get("host"):
        return

    db.execute(
        f"""
        INSERT INTO {ssl_table_sql}
            (site_id, host, port, checked_by, is_valid, valid_from, valid_to, days_remaining, issuer_name, issuer_cn, subject_cn, san, tls_version, cipher_name, error_message, checked_at)
        VALUES (%s, %s, %s, %s, %s, %s, %s, %s, %s, %s, %s, %s, %s, %s, %s, NOW())
        """,
        (
            site_id,
            ssl_result.get("host"),
            int(ssl_result.get("port") or 443),
            "pyt",
            ssl_result.get("is_valid"),
            ssl_result.get("valid_from"),
            ssl_result.get("valid_to"),
            ssl_result.get("days_remaining"),
            ssl_result.get("issuer_name"),
            ssl_result.get("issuer_cn"),
            ssl_result.get("subject_cn"),
            ssl_result.get("san"),
            ssl_result.get("tls_version"),
            ssl_result.get("cipher_name"),
            ssl_result.get("error_message"),
        ),
    )


def log_probe_check(log_dir: Path, site_id: int, url: str, probe_type: str, status: str) -> None:
    log_dir.mkdir(parents=True, exist_ok=True)
    probe_log = log_dir / "probe_checks.log"
    timestamp = datetime.now().strftime("%Y-%m-%d %H:%M:%S")
    line = f"[{timestamp}] Site ID: {site_id}, URL: {url}, Probe Type: {probe_type}, Status: {status}\n"
    with probe_log.open("a", encoding="utf-8") as handle:
        handle.write(line)


def _http_probe(
    url: str,
    headers: Dict[str, str],
    cookie_jar: http.cookiejar.CookieJar,
    *,
    method: str,
    follow_redirects: bool,
) -> Dict[str, Any]:
    handlers: List[Any] = [HTTPCookieProcessor(cookie_jar)]
    if not follow_redirects:
        handlers.append(NoRedirectHandler())
    opener = build_opener(*handlers)
    req = Request(url, headers=headers, method=method)
    start = time.perf_counter()
    http_code = 0
    redirected = False
    final_url = url

    try:
        with opener.open(req, timeout=25) as response:
            http_code = int(response.getcode() or 0)
            final_url = str(response.geturl() or url)
            redirected = final_url.rstrip("/") != url.rstrip("/")
            response.read(1024)
    except HTTPError as exc:
        http_code = int(getattr(exc, "code", 0) or 0)
        final_url = str(getattr(exc, "url", "") or url)
        redirected = final_url.rstrip("/") != url.rstrip("/")
    except (URLError, TimeoutError, OSError):
        http_code = 0
    except Exception:
        http_code = 0

    elapsed_ms = round((time.perf_counter() - start) * 1000, 2)
    online = _http_code_is_online(http_code)
    return {
        "status": "online" if online else "offline",
        "response_time": elapsed_ms if online else None,
        "http_code": http_code,
        "method": method,
        "follow_redirects": bool(follow_redirects),
        "redirected": bool(redirected),
        "final_url": final_url,
    }


def _run_http_variant(
    url: str,
    method: str,
    follow_redirects: bool,
    cookie_jar: http.cookiejar.CookieJar,
) -> Dict[str, Any]:
    result = _http_probe(
        url,
        {
            "User-Agent": "Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/135.0.0.0 Safari/537.36"
        },
        cookie_jar,
        method=method,
        follow_redirects=follow_redirects,
    )
    if result.get("status") == "offline" and int(result.get("http_code") or 0) == 0:
        result = _http_probe(
            url,
            {
                "User-Agent": "Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/18.0 Safari/605.1.15",
                "Referer": "https://www.google.com",
            },
            cookie_jar,
            method=method,
            follow_redirects=follow_redirects,
        )
    return result


def check_http_status(url: str, cfg: Dict[str, str]) -> Dict[str, Any]:
    cookie_jar = http.cookiejar.CookieJar()
    methods = _parse_http_methods(cfg.get("http_methods", ""))
    redirect_modes = _parse_redirect_modes(cfg.get("http_redirect_modes", ""))
    checks: List[Dict[str, Any]] = []

    for method in methods:
        for redirect_mode in redirect_modes:
            checks.append(
                _run_http_variant(
                    url,
                    method=method,
                    follow_redirects=_redirect_mode_to_bool(redirect_mode),
                    cookie_jar=cookie_jar,
                )
            )

    primary_method = str(cfg.get("http_primary_method", "GET") or "GET").strip().upper()
    if primary_method not in SUPPORTED_HTTP_METHODS:
        primary_method = "GET"
    primary_redirect_values = _parse_redirect_modes(cfg.get("http_primary_redirect", "follow"))
    primary_redirect_mode = primary_redirect_values[0] if primary_redirect_values else "follow"
    primary_follow = _redirect_mode_to_bool(primary_redirect_mode)

    primary = None
    for check in checks:
        if str(check.get("method")) == primary_method and bool(check.get("follow_redirects")) == primary_follow:
            primary = check
            break
    if primary is None and checks:
        primary = checks[0]
    if primary is None:
        primary = {
            "status": "offline",
            "response_time": None,
            "http_code": 0,
            "method": primary_method,
            "follow_redirects": primary_follow,
            "redirected": False,
            "final_url": url,
        }

    matrix_online = sum(1 for check in checks if str(check.get("status")) == "online")
    matrix_offline = len(checks) - matrix_online
    return {
        "status": str(primary.get("status") or "offline"),
        "response_time": primary.get("response_time"),
        "http_code": int(primary.get("http_code") or 0),
        "http_method": str(primary.get("method") or primary_method),
        "follow_redirects": bool(primary.get("follow_redirects")),
        "redirected": bool(primary.get("redirected")),
        "matrix_total": len(checks),
        "matrix_online": matrix_online,
        "matrix_offline": matrix_offline,
        "matrix_checks": checks,
    }


def check_icmp_status(host: str) -> Dict[str, Any]:
    start = time.perf_counter()
    status_code = 1
    try:
        completed = subprocess.run(
            ["ping", "-c", "1", "-W", "1", host],
            check=False,
            stdout=subprocess.DEVNULL,
            stderr=subprocess.DEVNULL,
            timeout=3,
        )
        status_code = int(completed.returncode)
    except Exception:
        status_code = 1

    elapsed_ms = round((time.perf_counter() - start) * 1000, 2)
    online = status_code == 0
    return {
        "status": "online" if online else "offline",
        "response_time": elapsed_ms if online else None,
        "http_code": None,
    }


def _extract_tcp_target(value: str) -> Tuple[str, int]:
    target = (value or "").strip()
    if not target:
        return "", 0
    parsed = urlparse(target if "://" in target else "//" + target)
    try:
        port = int(parsed.port or 0)
    except ValueError:
        return "", 0
    host = parsed.hostname or ""
    if not host or port < 1 or port > 65535:
        return "", 0
    return host, port


def check_tcp_status(host: str, port: int) -> Dict[str, Any]:
    start = time.perf_counter()
    online = False
    try:
        with socket.create_connection((host, port), timeout=3):
            online = True
    except (OSError, ValueError):
        online = False

    elapsed_ms = round((time.perf_counter() - start) * 1000, 2)
    return {
        "status": "online" if online else "offline",
        "response_time": elapsed_ms if online else None,
        "http_code": None,
    }


def insert_probe_result(
    db: Database,
    site_id: int,
    probe_type: str,
    result: Dict[str, Any],
    probes_table_sql: str,
    source_node: str,
) -> None:
    status = result.get("status")
    response_time = result.get("response_time") if status == "online" else None
    http_code = result.get("http_code")
    source_probe_id = int(time.time_ns() // 1000)

    try:
        db.execute(
            f"""
            INSERT INTO {probes_table_sql}
                (site_id, probe_type, status, response_time, http_code, checked_by, checked_at, source_node, source_probe_id)
            VALUES (%s, %s, %s, %s, %s, %s, NOW(), %s, %s)
            """,
            (
                site_id,
                probe_type,
                status,
                response_time,
                http_code,
                "pyt",
                source_node,
                source_probe_id,
            ),
        )
        return
    except Exception:
        pass

    db.execute(
        f"""
        INSERT INTO {probes_table_sql}
            (site_id, probe_type, status, response_time, http_code, checked_by, checked_at)
        VALUES (%s, %s, %s, %s, %s, %s, NOW())
        """,
        (
            site_id,
            probe_type,
            status,
            response_time,
            http_code,
            "pyt",
        ),
    )


def _sanitize_http_code(value: Any) -> Optional[int]:
    try:
        if value is None:
            return None
        return int(value)
    except Exception:
        return None


def _generate_postmortem(
    _monitoring_root: Path,
    _site_url: str,
    started_at: Optional[datetime],
    ended_at: Optional[datetime],
    http_code: Optional[int],
) -> Tuple[str, bool]:
    if not started_at or not ended_at:
        return ("", True)
    duration_sec = max(0, int((ended_at - started_at).total_seconds()))
    minutes, seconds = divmod(duration_sec, 60)
    hours, minutes = divmod(minutes, 60)
    if hours > 0:
        duration = f"{hours} h {minutes} min"
    elif minutes > 0:
        duration = f"{minutes} min {seconds} s"
    else:
        duration = f"{seconds} s"
    details = f"Incident resolved after {duration} of downtime."
    if http_code is not None and http_code > 0:
        details += f" Last observed HTTP code: {http_code}."
    return (details, False)


def _sanitize_postmortem_text(raw: str) -> str:
    text = str(raw or "").replace("\r", " ").replace("\n", " ").replace("\t", " ").strip()
    text = " ".join(text.split())
    if not text:
        return ""

    lowered = text.lower()
    if "content-type:" in lowered or "http error:" in lowered or "curl error:" in lowered or "debug:" in lowered:
        return ""

    text = text.strip(" \"'`")
    if len(text) < 20:
        return ""
    if not any(ch.isalpha() for ch in text):
        return ""

    return text[:280]


def _group_root_domain(host: str) -> str:
    value = (host or "").strip().lower().strip(".")
    if value.startswith("www."):
        value = value[4:]
    parts = [p for p in value.split(".") if p]
    if len(parts) < 2:
        return value

    if len(parts) >= 3 and len(parts[-1]) == 2 and parts[-2] in {"co", "com", "net", "org", "gov", "edu", "ac"}:
        return ".".join(parts[-3:])
    return ".".join(parts[-2:])


def _domain_group_key(site_url: str) -> str:
    host = _extract_host(site_url)
    return _group_root_domain(host) or (host or site_url)


def _format_sites_for_sms(sites: Set[str], max_hosts: int = 2) -> str:
    hosts = sorted({(_extract_host(url) or url).strip() for url in sites if (url or "").strip()})
    if not hosts:
        return "unknown site"
    if len(hosts) <= max_hosts:
        return ", ".join(hosts)
    return f"{', '.join(hosts[:max_hosts])} +{len(hosts) - max_hosts}"


def _send_grouped_sms(user: str, password: str, message: str, logger: logging.Logger) -> bool:
    msg = " ".join(str(message or "").replace("\r", " ").replace("\n", " ").split()).strip()
    if not msg:
        return False
    if len(msg) > 480:
        msg = msg[:477].rstrip() + "..."
    sent = send_sms(user, password, msg)
    if not sent:
        logger.warning("Grouped SMS notification failed: %s", msg)
    return bool(sent)


def _load_notification_targets(db: Database, cfg: Dict[str, str], logger: logging.Logger) -> Dict[str, Any]:
    targets: Dict[str, Any] = {"sms": [], "emails": []}
    sms_user = str(cfg.get("sms_user", "") or "").strip()
    sms_password = str(cfg.get("sms_password", "") or "").strip()
    email_seen: Set[str] = set()

    for raw_email in str(cfg.get("notification_emails", "") or "").replace(";", ",").split(","):
        email = raw_email.strip()
        if not email or "@" not in email:
            continue
        key = email.lower()
        if key in email_seen:
            continue
        targets["emails"].append({"email": email, "name": "Insight Team"})
        email_seen.add(key)

    if sms_user and sms_password:
        targets["sms"].append({"user": sms_user, "password": sms_password})

    return targets


def _send_grouped_email(
    recipients: List[Dict[str, str]],
    subject: str,
    message: str,
    cfg: Dict[str, str],
    logger: logging.Logger,
) -> Dict[str, int]:
    stats = {"targeted": 0, "sent": 0, "failed": 0}
    if not recipients:
        return stats

    smtp_host = str(cfg.get("email_smtp_host", "") or "").strip()
    smtp_port = str(cfg.get("email_smtp_port", "") or "").strip()
    smtp_username = str(cfg.get("email_smtp_username", "") or "").strip()
    smtp_password = str(cfg.get("email_smtp_password", "") or "").strip()
    smtp_encryption = str(cfg.get("email_smtp_encryption", "ssl") or "ssl").strip()
    from_name = str(cfg.get("email_from_name", "Insight") or "Insight").strip()

    if not smtp_host or not smtp_username or not smtp_password:
        return stats

    text = " ".join(str(message or "").replace("\r", " ").replace("\n", " ").split()).strip()
    if not text:
        return stats
    if len(text) > 900:
        text = text[:897].rstrip() + "..."

    safe_subject = str(subject or "").strip() or "Updates monitoring"
    seen: Set[str] = set()
    for recipient in recipients:
        email = str(recipient.get("email") or "").strip()
        if not email:
            continue
        key = email.lower()
        if key in seen:
            continue
        seen.add(key)
        stats["targeted"] += 1
        name = str(recipient.get("name") or "").strip() or "Team member"
        sent = send_email_smtp(
            smtp_host,
            smtp_port,
            smtp_username,
            smtp_password,
            smtp_encryption,
            from_name,
            email,
            name,
            safe_subject,
            text,
        )
        if sent:
            stats["sent"] += 1
        else:
            stats["failed"] += 1
            logger.warning("Grouped email notification failed for %s", email)
    return stats


def _dispatch_grouped_updates(
    db: Database,
    targets: Dict[str, Any],
    event_key: str,
    subject: str,
    message: str,
    context: Dict[str, Any],
    cfg: Dict[str, str],
    logger: logging.Logger,
) -> None:
    try:
        result = dispatch_event(db, cfg, event_key, context)
        if int(result.get("configured") or 0) > 0:
            if int(result.get("failed") or 0) > 0:
                logger.warning(
                    "Notification event %s failed for %s channel(s).",
                    event_key,
                    int(result.get("failed") or 0),
                )
            return
    except Exception as exc:
        logger.warning("Notification dispatch failed for %s: %s", event_key, exc)

    sms_targets = targets.get("sms") if isinstance(targets, dict) else []
    if isinstance(sms_targets, list):
        seen: Set[str] = set()
        for target in sms_targets:
            if not isinstance(target, dict):
                continue
            user = str(target.get("user") or "").strip()
            password = str(target.get("password") or "").strip()
            if not user or not password:
                continue
            key = f"{user}|{password}"
            if key in seen:
                continue
            seen.add(key)
            _send_grouped_sms(user, password, message, logger)

    recipients = targets.get("emails") if isinstance(targets, dict) else []
    if isinstance(recipients, list):
        _send_grouped_email(recipients, subject, message, cfg, logger)


class NotificationBatch:
    def __init__(self) -> None:
        self.incident_open: Dict[str, Set[str]] = {}
        self.incident_close: Dict[Tuple[str, str], Dict[str, Any]] = {}
        self.status_offline: Dict[str, Set[str]] = {}
        self.status_online: Dict[str, Set[str]] = {}

    def queue_incident_open(self, site_url: str) -> None:
        domain = _domain_group_key(site_url)
        self.incident_open.setdefault(domain, set()).add(site_url)

    def queue_incident_close(self, site_url: str, pm_text: str, timeout: bool) -> None:
        domain = _domain_group_key(site_url)
        safe_pm = _sanitize_postmortem_text(pm_text)
        timed_out = bool(timeout) or safe_pm == ""
        pm_key = "__no_pm__" if timed_out else safe_pm.lower()
        key = (domain, pm_key)

        bucket = self.incident_close.setdefault(
            key,
            {
                "domain": domain,
                "pm_text": "" if timed_out else safe_pm,
                "timeout": timed_out,
                "sites": set(),
            },
        )
        bucket["sites"].add(site_url)

    def queue_status_offline(self, site_url: str) -> None:
        domain = _domain_group_key(site_url)
        self.status_offline.setdefault(domain, set()).add(site_url)

    def queue_status_online(self, site_url: str) -> None:
        domain = _domain_group_key(site_url)
        self.status_online.setdefault(domain, set()).add(site_url)

    def flush(self, db: Database, targets: Dict[str, Any], cfg: Dict[str, str], logger: logging.Logger) -> None:
        for domain, sites in sorted(self.incident_open.items(), key=lambda x: x[0]):
            count = len(sites)
            msg = (
                f"Incident opened ({domain}): {count} unavailable site{'s' if count > 1 else ''}"
                f"{'s' if count > 1 else ''} ({_format_sites_for_sms(sites)})."
            )
            _dispatch_grouped_updates(
                db,
                targets,
                "incident_open",
                f"Incident opened - {domain}",
                msg,
                {
                    "app_name": cfg.get("app_name", "Insight"),
                    "public_url": cfg.get("public_url", ""),
                    "domain": domain,
                    "sites": ", ".join(sorted(sites)),
                    "site_url": sorted(sites)[0],
                    "count": count,
                    "status": "offline",
                    "message": "Detection confirmed by the monitoring engine.",
                },
                cfg,
                logger,
            )

        for _, payload in sorted(self.incident_close.items(), key=lambda x: (x[1]["domain"], x[0][1])):
            sites = payload["sites"]
            count = len(sites)
            domain = payload["domain"]
            if payload["timeout"]:
                msg = (
                    f"Incident resolved ({domain}): {count} restored site{'s' if count > 1 else ''}"
                    f"{'s' if count > 1 else ''} ({_format_sites_for_sms(sites)}). "
                    "Report unavailable; see the public Insight page."
                )
            else:
                msg = (
                    f"Incident resolved ({domain}): {count} restored site{'s' if count > 1 else ''}"
                    f"{'s' if count > 1 else ''} ({_format_sites_for_sms(sites)}). "
                    f"Cause probable : {payload['pm_text']}"
                )
            resolution_message = (
                "The service is responding again, but the resolution report is unavailable."
                if payload["timeout"]
                else f"Cause probable : {payload['pm_text']}"
            )
            _dispatch_grouped_updates(
                db,
                targets,
                "incident_resolved",
                f"Incident resolved - {domain}",
                msg,
                {
                    "app_name": cfg.get("app_name", "Insight"),
                    "public_url": cfg.get("public_url", ""),
                    "domain": domain,
                    "sites": ", ".join(sorted(sites)),
                    "site_url": sorted(sites)[0],
                    "count": count,
                    "status": "online",
                    "message": resolution_message,
                },
                cfg,
                logger,
            )

        for domain, sites in sorted(self.status_offline.items(), key=lambda x: x[0]):
            count = len(sites)
            msg = (
                f"Alert ({domain}): {count} offline site{'s' if count > 1 else ''}"
                f" ({_format_sites_for_sms(sites)})."
            )
            _dispatch_grouped_updates(
                db,
                targets,
                "monitor_down",
                f"Offline alert - {domain}",
                msg,
                {
                    "app_name": cfg.get("app_name", "Insight"),
                    "public_url": cfg.get("public_url", ""),
                    "domain": domain,
                    "sites": ", ".join(sorted(sites)),
                    "site_url": sorted(sites)[0],
                    "count": count,
                    "status": "offline",
                    "message": "The latest check received no valid response.",
                },
                cfg,
                logger,
            )

        for domain, sites in sorted(self.status_online.items(), key=lambda x: x[0]):
            count = len(sites)
            msg = (
                f"Alert ({domain}): {count} site{'s are' if count > 1 else ' is'} back online"
                f" ({_format_sites_for_sms(sites)})."
            )
            _dispatch_grouped_updates(
                db,
                targets,
                "monitor_up",
                f"Service restored - {domain}",
                msg,
                {
                    "app_name": cfg.get("app_name", "Insight"),
                    "public_url": cfg.get("public_url", ""),
                    "domain": domain,
                    "sites": ", ".join(sorted(sites)),
                    "site_url": sorted(sites)[0],
                    "count": count,
                    "status": "online",
                    "message": "The recovery check succeeded.",
                },
                cfg,
                logger,
            )


def _insert_escalation_event_pending(
    db: Database,
    incident_id: int,
    step_key: str,
    channel: str,
    delay_minutes: int,
) -> bool:
    try:
        db.execute(
            """
            INSERT INTO monitoring_escalation_events
                (incident_id, step_key, channel, delay_minutes, status, details, triggered_at)
            VALUES (%s, %s, %s, %s, 'pending', '', NOW())
            """,
            (incident_id, step_key, channel, delay_minutes),
        )
        return True
    except Exception as exc:
        message = str(exc).lower()
        if "duplicate" in message or "1062" in message:
            return False
        raise


def _update_escalation_event_status(
    db: Database,
    incident_id: int,
    step_key: str,
    status: str,
    details: str,
) -> None:
    safe_details = " ".join(str(details or "").replace("\r", " ").replace("\n", " ").split()).strip()[:255]
    db.execute(
        """
        UPDATE monitoring_escalation_events
        SET status = %s, details = %s, triggered_at = NOW()
        WHERE incident_id = %s AND step_key = %s
        LIMIT 1
        """,
        (status, safe_details, incident_id, step_key),
    )


def _dispatch_escalation_step(
    step: Dict[str, Any],
    incident_id: int,
    site_url: str,
    elapsed_seconds: int,
    targets: Dict[str, Any],
    cfg: Dict[str, str],
    logger: logging.Logger,
) -> Tuple[str, str]:
    channel = _normalize_escalation_channel(step.get("channel"))
    label = str(step.get("label") or channel.upper()).strip() or channel.upper()
    incident_ref = _escalation_incident_ref(incident_id, site_url)

    if (channel == "call") or bool(step.get("is_placeholder")):
        return ("placeholder", "Appel en placeholder")

    if channel == "email":
        recipients = targets.get("emails") if isinstance(targets, dict) else []
        if not isinstance(recipients, list) or len(recipients) == 0:
            return ("skipped", "No active email recipient")
        subject = _escalation_subject(label, incident_ref)
        message = _escalation_message(label, incident_ref, elapsed_seconds, compact=False)
        stats = _send_grouped_email(recipients, subject, message, cfg, logger)
        targeted = int(stats.get("targeted") or 0)
        sent = int(stats.get("sent") or 0)
        failed = int(stats.get("failed") or 0)
        if targeted <= 0:
            return ("skipped", "No active email recipient")
        if sent <= 0:
            return ("failed", f"Email 0/{targeted} sent")
        if failed > 0:
            return ("sent", f"Email {sent}/{targeted} sent, {failed} failed")
        return ("sent", f"Email {sent}/{targeted} sent")

    if channel == "sms":
        sms_targets = targets.get("sms") if isinstance(targets, dict) else []
        if not isinstance(sms_targets, list) or len(sms_targets) == 0:
            return ("skipped", "No active SMS recipient")
        message = _escalation_message(label, incident_ref, elapsed_seconds, compact=True)
        seen: Set[str] = set()
        targeted = 0
        sent = 0
        for sms_target in sms_targets:
            if not isinstance(sms_target, dict):
                continue
            user = str(sms_target.get("user") or "").strip()
            password = str(sms_target.get("password") or "").strip()
            if not user or not password:
                continue
            key = f"{user}|{password}"
            if key in seen:
                continue
            seen.add(key)
            targeted += 1
            if _send_grouped_sms(user, password, message, logger):
                sent += 1
        if targeted <= 0:
            return ("skipped", "No active SMS recipient")
        failed = max(0, targeted - sent)
        if sent <= 0:
            return ("failed", f"SMS 0/{targeted} sent")
        if failed > 0:
            return ("sent", f"SMS {sent}/{targeted} sent, {failed} failed")
        return ("sent", f"SMS {sent}/{targeted} sent")

    return ("skipped", "Unsupported escalation channel")


def apply_escalation_policy(
    db: Database,
    cfg: Dict[str, str],
    incidents_table_sql: str,
    targets: Dict[str, Any],
    logger: logging.Logger,
) -> Dict[str, int]:
    stats = {"triggered": 0, "sent": 0, "failed": 0, "skipped": 0, "placeholder": 0}
    max_age_minutes = max(1, _parse_int(cfg.get("monitoring_escalation_max_age_minutes", "360"), 360))
    max_age_seconds = max_age_minutes * 60
    max_dispatch_per_run = max(
        1,
        _parse_int(cfg.get("monitoring_escalation_max_notifications_per_run", "20"), 20),
    )
    dispatched_count = 0
    policy = load_escalation_policy(db)
    enabled_steps = [step for step in policy if bool(step.get("enabled"))]
    if not enabled_steps:
        return stats

    incidents = db.query_all(
        f"""
        SELECT i.id, i.started_at, s.url AS site_url
        FROM {incidents_table_sql} i
        LEFT JOIN `sites` s ON s.id = i.site_id
        WHERE i.status = 0
          AND i.started_at IS NOT NULL
        ORDER BY i.started_at ASC, i.id ASC
        """
    )
    if not incidents:
        return stats

    now_dt = datetime.now()
    for incident in incidents:
        incident_id = _parse_int(incident.get("id"), 0)
        if incident_id <= 0:
            continue
        started_at = _parse_datetime(incident.get("started_at"))
        if started_at is None:
            continue
        elapsed_seconds = max(0, int((now_dt - started_at).total_seconds()))
        site_url = str(incident.get("site_url") or "").strip()
        is_stale = elapsed_seconds > max_age_seconds
        has_site = site_url != ""
        for step in enabled_steps:
            step_key = _normalize_escalation_step_key(step.get("step_key"))
            if not step_key:
                continue
            delay_minutes = _normalize_escalation_delay(step.get("delay_minutes"), 1)
            if elapsed_seconds < (delay_minutes * 60):
                continue
            try:
                inserted = _insert_escalation_event_pending(
                    db,
                    incident_id,
                    step_key,
                    _normalize_escalation_channel(step.get("channel")),
                    delay_minutes,
                )
            except Exception as exc:
                logger.warning("Escalation insert failed for incident %s step %s: %s", incident_id, step_key, exc)
                continue
            if not inserted:
                continue

            if is_stale:
                status = "skipped"
                details = (
                    "Incident skipped: age "
                    + _format_escalation_duration(elapsed_seconds)
                    + f" (>{max_age_minutes} min)."
                )
            elif not has_site:
                status = "skipped"
                details = "Incident skipped: site not found."
            elif dispatched_count >= max_dispatch_per_run:
                status = "skipped"
                details = f"Escalation limited: per-run quota ({max_dispatch_per_run}) reached."
            else:
                dispatched_count += 1
                try:
                    status, details = _dispatch_escalation_step(step, incident_id, site_url, elapsed_seconds, targets, cfg, logger)
                except Exception as exc:
                    status = "failed"
                    details = f"Delivery error: {exc}"
                    logger.warning("Escalation dispatch failed for incident %s step %s: %s", incident_id, step_key, exc)

            try:
                _update_escalation_event_status(db, incident_id, step_key, status, details)
            except Exception as exc:
                logger.warning("Escalation update failed for incident %s step %s: %s", incident_id, step_key, exc)

            stats["triggered"] += 1
            if status in stats:
                stats[status] += 1
            logger.info(
                "Escalation incident=%s step=%s channel=%s status=%s details=%s",
                incident_id,
                step_key,
                _normalize_escalation_channel(step.get("channel")),
                status,
                details,
            )
    return stats


def _open_incident_if_needed(
    db: Database,
    cfg: Dict[str, str],
    site_id: int,
    site_url: str,
    current_result: Dict[str, Any],
    logger: logging.Logger,
    probes_table_sql: str,
    incidents_table_sql: str,
    send_notifications: bool = True,
    notification_batch: Optional[NotificationBatch] = None,
) -> bool:
    recent = db.query_all(
        f"SELECT status FROM {probes_table_sql} WHERE site_id = %s ORDER BY checked_at DESC LIMIT 3",
        (site_id,),
    )
    three_offline = len(recent) == 3 and all(str(r.get("status")) == "offline" for r in recent)
    if not three_offline:
        return False

    already_open = db.query_one(
        f"SELECT id FROM {incidents_table_sql} WHERE site_id = %s AND status = 0 LIMIT 1",
        (site_id,),
    )
    if already_open:
        return False

    db.execute(
        f"INSERT INTO {incidents_table_sql} (site_id, started_at, http_code, postmortem, ai_created) VALUES (%s, NOW(), %s, NULL, 0)",
        (site_id, _sanitize_http_code(current_result.get("http_code"))),
    )

    if send_notifications:
        if notification_batch is not None:
            notification_batch.queue_incident_open(site_url)
        else:
            sent = send_sms(
                cfg.get("sms_user", ""),
                cfg.get("sms_password", ""),
                f"Incident opened. {site_url} did not respond after several attempts.",
            )
            if not sent:
                logger.warning("Incident open notification failed for site %s", site_url)
    logger.info("Incident opened for site %s", site_url)
    return True


def _close_incident_if_needed(
    db: Database,
    cfg: Dict[str, str],
    site_id: int,
    site_url: str,
    current_result: Dict[str, Any],
    monitoring_root: Path,
    logger: logging.Logger,
    incidents_table_sql: str,
    send_notifications: bool = True,
    notification_batch: Optional[NotificationBatch] = None,
) -> bool:
    if current_result.get("status") != "online":
        return False

    row = db.query_one(
        f"""
        SELECT id
        FROM {incidents_table_sql}
        WHERE site_id = %s AND status = 0
        ORDER BY started_at DESC
        LIMIT 1
        """,
        (site_id,),
    )
    if not row:
        return False

    incident_id = int(row.get("id") or 0)
    if incident_id <= 0:
        return False

    db.execute(
        f"UPDATE {incidents_table_sql} SET ended_at = NOW(), status = 1 WHERE id = %s",
        (incident_id,),
    )

    info = db.query_one(
        f"SELECT started_at, ended_at, http_code FROM {incidents_table_sql} WHERE id = %s",
        (incident_id,),
    )

    started_at = _parse_datetime(info.get("started_at") if info else None)
    ended_at = _parse_datetime(info.get("ended_at") if info else None)
    http_code = _sanitize_http_code(info.get("http_code") if info else None)

    pm_text, timed_out = _generate_postmortem(monitoring_root, site_url, started_at, ended_at, http_code)

    safe_pm_text = _sanitize_postmortem_text(pm_text)
    if timed_out or not safe_pm_text:
        db.execute(
            f"UPDATE {incidents_table_sql} SET postmortem = NULL, ai_created = 0 WHERE id = %s",
            (incident_id,),
        )
    else:
        db.execute(
            f"UPDATE {incidents_table_sql} SET postmortem = %s, ai_created = 1 WHERE id = %s",
            (safe_pm_text, incident_id),
        )

    if send_notifications:
        if notification_batch is not None:
            notification_batch.queue_incident_close(site_url, pm_text, timed_out)
        else:
            if timed_out or not safe_pm_text:
                msg = (
                    f"Incident resolved because {site_url} appears to be available again. "
                    "The incident report is unavailable; see the public Insight page."
                )
            else:
                msg = (
                    f"Incident resolved because {site_url} appears to be available again. "
                    f"Incident summary: {safe_pm_text}"
                )
            sent = send_sms(cfg.get("sms_user", ""), cfg.get("sms_password", ""), msg)
            if not sent:
                logger.warning("Incident close notification failed for site %s", site_url)
    logger.info("Incident closed for site %s", site_url)
    return True


def _alert_site(
    db: Database,
    cfg: Dict[str, str],
    site_id: int,
    site_url: str,
    status: str,
    *,
    alert_table_sql: str,
    probes_table_sql: str,
    logger: logging.Logger,
    send_notifications: bool = True,
    suppress_recovery_alert: bool = False,
    notification_batch: Optional[NotificationBatch] = None,
) -> None:
    previous = db.query_one(f"SELECT status, alert_sent FROM {alert_table_sql} WHERE id = %s", (site_id,))
    alert_sent_previously = bool(int(previous.get("alert_sent") or 0)) if previous else False
    alert_sent = alert_sent_previously

    recent = db.query_all(
        f"SELECT status FROM {probes_table_sql} WHERE site_id = %s ORDER BY checked_at DESC LIMIT 2",
        (site_id,),
    )
    consecutive_offline = len(recent) == 2 and all(str(r.get("status")) == "offline" for r in recent)

    if status != "online":
        if consecutive_offline and not alert_sent_previously:
            if send_notifications:
                if notification_batch is not None:
                    notification_batch.queue_status_offline(site_url)
                else:
                    sent = send_sms(
                        cfg.get("sms_user", ""),
                        cfg.get("sms_password", ""),
                        f"Alert: {site_url} is offline",
                    )
                    if not sent:
                        logger.warning("Offline SMS notification failed for site %s", site_url)
            alert_sent = True
    else:
        if alert_sent_previously:
            if send_notifications and not suppress_recovery_alert:
                if notification_batch is not None:
                    notification_batch.queue_status_online(site_url)
                else:
                    sent = send_sms(
                        cfg.get("sms_user", ""),
                        cfg.get("sms_password", ""),
                        f"Alert: {site_url} is back online",
                    )
                    if not sent:
                        logger.warning("Back-online SMS notification failed for site %s", site_url)
            alert_sent = False

    if previous:
        db.execute(
            f"UPDATE {alert_table_sql} SET site_url = %s, status = %s, alert_sent = %s, timestamp = NOW() WHERE id = %s",
            (site_url, status, 1 if alert_sent else 0, site_id),
        )
    else:
        db.execute(
            f"INSERT INTO {alert_table_sql} (id, site_url, status, alert_sent, timestamp) VALUES (%s, %s, %s, %s, NOW())",
            (site_id, site_url, status, 1 if alert_sent else 0),
        )


def _extract_host(url: str) -> str:
    value = (url or "").strip()
    if not value:
        return ""

    parsed = urlparse(value)
    host = parsed.hostname
    if host:
        return host

    parsed = urlparse("https://" + value)
    return parsed.hostname or value


def _resolve_monitoring_concurrency(cfg: Dict[str, str], due_count: int) -> int:
    requested = _parse_int(cfg.get("monitoring_concurrency", "4"), 4)
    configured_max = _parse_int(cfg.get("monitoring_concurrency_max", "24"), 24)
    if configured_max < 1:
        configured_max = 1
    if configured_max > 64:
        configured_max = 64
    if requested < 1:
        requested = 1
    if requested > configured_max:
        requested = configured_max
    if due_count <= 0:
        return 1
    return min(requested, due_count)


def _run_site_probe_task(job: Dict[str, Any]) -> Dict[str, Any]:
    probe_family = str(job.get("probe_family") or "")
    probe_type_raw = str(job.get("probe_type_raw") or "http")
    site_url = str(job.get("site_url") or "")

    if probe_family == "http":
        site_http_cfg = job.get("site_http_cfg")
        if not isinstance(site_http_cfg, dict):
            site_http_cfg = {}
        result = check_http_status(site_url, site_http_cfg)
        ssl_result = check_ssl_certificate(site_url)
        return {
            "ok": True,
            "probe_family": "http",
            "probe_type_raw": probe_type_raw,
            "result": result,
            "ssl_result": ssl_result,
        }

    if probe_family == "icmp":
        host = _extract_host(site_url)
        if not host:
            return {
                "ok": False,
                "probe_family": "icmp",
                "probe_type_raw": probe_type_raw,
                "error": "invalid_host",
            }
        result = check_icmp_status(host)
        return {
            "ok": True,
            "probe_family": "icmp",
            "probe_type_raw": probe_type_raw,
            "result": result,
            "ssl_result": None,
        }

    if probe_family == "tcp":
        host, port = _extract_tcp_target(site_url)
        if not host or port <= 0:
            return {
                "ok": False,
                "probe_family": "tcp",
                "probe_type_raw": probe_type_raw,
                "error": "invalid_tcp_target",
            }
        result = check_tcp_status(host, port)
        return {
            "ok": True,
            "probe_family": "tcp",
            "probe_type_raw": probe_type_raw,
            "result": result,
            "ssl_result": None,
        }

    return {
        "ok": False,
        "probe_family": probe_family,
        "probe_type_raw": probe_type_raw,
        "error": "unsupported_probe",
    }


def run_manual_check(site_url: str, probe_type: str, cfg: Dict[str, str]) -> Dict[str, Any]:
    url = str(site_url or "").strip()
    if not url:
        return {"ok": False, "status_code": 422, "message": "URL vide."}

    probe = str(probe_type or "http").strip().lower()
    if probe not in {"http", "ping", "icmp", "tcp"}:
        return {
            "ok": False,
            "status_code": 422,
            "message": f"The {probe} probe requires a Blackbox Exporter agent.",
        }

    if probe in {"ping", "icmp"}:
        host = _extract_host(url)
        if not host:
            return {"ok": False, "status_code": 422, "message": "Invalid ICMP host."}
        result = check_icmp_status(host)
        return {
            "ok": True,
            "url": url,
            "probe_type": "icmp",
            "result": result,
        }

    if probe == "tcp":
        host, port = _extract_tcp_target(url)
        if not host or port <= 0:
            return {"ok": False, "status_code": 422, "message": "Invalid TCP target. Use host:port."}
        result = check_tcp_status(host, port)
        return {
            "ok": True,
            "url": url,
            "probe_type": "tcp",
            "result": result,
        }

    result = check_http_status(url, cfg)
    return {
        "ok": True,
        "url": url,
        "probe_type": "http",
        "result": result,
    }


def run_monitor_job(db: Database, cfg: Dict[str, str], monitoring_root: Path) -> Dict[str, Any]:
    suffix = str(cfg.get("table_suffix", "") or "").strip()
    logger_key = suffix.lstrip("_") if suffix else "main"
    logger = _setup_logger(monitoring_root / "logs", logger_key=logger_key)

    shadow_mode = is_shadow_mode(cfg)
    send_notifications = not is_truthy(cfg.get("disable_notifications"))
    escalation_enabled = bool(send_notifications)
    notification_targets: Dict[str, Any] = {"sms": [], "emails": []}
    if send_notifications:
        has_modern_channels = has_notification_channels(db)
        notification_targets = _load_notification_targets(db, cfg, logger)
        has_sms = isinstance(notification_targets.get("sms"), list) and len(notification_targets.get("sms")) > 0
        has_email = isinstance(notification_targets.get("emails"), list) and len(notification_targets.get("emails")) > 0
        if not has_modern_channels and not has_sms and not has_email:
            logger.warning("No notification targets available. Notifications are disabled.")
            send_notifications = False
    notification_batch = NotificationBatch() if send_notifications else None

    sites_table_sql = table_sql("sites", {"table_suffix": ""})
    probes_table = table_name("probes", cfg)
    probes_table_sql = table_sql("probes", cfg)
    ssl_table = table_name("ssl_checks", cfg)
    ssl_table_sql = table_sql("ssl_checks", cfg)
    incidents_table = table_name("incidents", cfg)
    incidents_table_sql = table_sql("incidents", cfg)
    alert_table = table_name("alert", cfg)
    alert_table_sql = table_sql("alert", cfg)

    db.execute(f"DELETE FROM {alert_table_sql} WHERE id NOT IN (SELECT id FROM {sites_table_sql})")
    ensure_ssl_checks_table(db, logger, ssl_table)
    ensure_probes_checked_by_column(db, logger, probes_table)
    local_source_node = _monitoring_source_node(cfg)
    ensure_sites_runtime_columns(db, logger)
    try:
        ensure_scheduled_maintenances_table(db)
    except Exception as exc:
        logger.warning("Unable to ensure scheduled_maintenances table: %s", exc)
    try:
        ensure_escalation_tables(db)
    except Exception as exc:
        logger.warning("Unable to ensure escalation tables: %s", exc)

    sites = db.query_all(
        f"""
        SELECT
            id,
            url,
            probe_type,
            probe_interval_sec,
            calc_method,
            http_methods,
            http_redirect_modes,
            http_primary_method,
            http_primary_redirect
        FROM {sites_table_sql}
        """
    )
    site_ids = [int(site.get("id") or 0) for site in sites if int(site.get("id") or 0) > 0]
    maintenance_global_active = False
    maintenance_site_ids: Set[int] = set()
    try:
        maintenance_global_active, maintenance_site_ids = get_active_maintenance_scope(db, site_ids)
    except Exception as exc:
        logger.warning("Unable to load maintenance scope: %s", exc)

    processed = 0
    errors = 0
    opened = 0
    closed = 0
    under_maintenance = 0
    skipped_scheduler = 0
    now_ts = int(time.time())
    force_run = is_truthy(cfg.get("scheduler_force_run"))
    tolerance_sec = max(0, _parse_int(cfg.get("scheduler_tolerance_sec", "5"), 5))
    http_interval_sec = _normalize_interval(cfg.get("http_interval_sec", "60"), 60)
    icmp_interval_sec = _normalize_interval(cfg.get("icmp_interval_sec", "60"), 60)
    http_methods = _parse_http_methods(cfg.get("http_methods", ""))
    http_redirect_modes = _parse_redirect_modes(cfg.get("http_redirect_modes", ""))

    due_jobs: List[Dict[str, Any]] = []
    for site in sites:
        site_id = int(site.get("id") or 0)
        site_url = str(site.get("url") or "")
        probe_type_raw = str(site.get("probe_type") or "http").strip().lower()

        if site_id <= 0 or not site_url:
            continue

        probe_family = ""
        if probe_type_raw == "http":
            probe_family = "http"
        elif probe_type_raw in {"ping", "icmp"}:
            probe_family = "icmp"
        elif probe_type_raw == "tcp":
            probe_family = "tcp"
        else:
            continue

        site_interval_raw = site.get("probe_interval_sec")
        default_interval = http_interval_sec if probe_family == "http" else icmp_interval_sec
        interval_sec = _normalize_interval(site_interval_raw, default_interval)
        if not _is_interval_due(now_ts, interval_sec, tolerance_sec, force_run):
            skipped_scheduler += 1
            continue

        site_http_cfg: Dict[str, str] = {}
        if probe_family == "http":
            site_http_cfg = {
                "http_methods": str(site.get("http_methods") or cfg.get("http_methods", "")),
                "http_redirect_modes": str(site.get("http_redirect_modes") or cfg.get("http_redirect_modes", "")),
                "http_primary_method": str(site.get("http_primary_method") or cfg.get("http_primary_method", "GET")),
                "http_primary_redirect": str(site.get("http_primary_redirect") or cfg.get("http_primary_redirect", "follow")),
            }
        due_jobs.append(
            {
                "site_id": site_id,
                "site_url": site_url,
                "probe_type_raw": probe_type_raw,
                "probe_family": probe_family,
                "site_http_cfg": site_http_cfg,
            }
        )

    concurrency = _resolve_monitoring_concurrency(cfg, len(due_jobs))
    probe_results: Dict[int, Dict[str, Any]] = {}
    if concurrency <= 1:
        for job in due_jobs:
            probe_results[int(job["site_id"])] = _run_site_probe_task(job)
    else:
        with ThreadPoolExecutor(max_workers=concurrency) as executor:
            future_map = {executor.submit(_run_site_probe_task, job): int(job["site_id"]) for job in due_jobs}
            for future in as_completed(future_map):
                site_id = future_map[future]
                try:
                    probe_results[site_id] = future.result()
                except Exception as exc:
                    probe_results[site_id] = {"ok": False, "error": str(exc)}

    for job in due_jobs:
        site_id = int(job.get("site_id") or 0)
        site_url = str(job.get("site_url") or "")
        probe_type_raw = str(job.get("probe_type_raw") or "http")
        probe_family = str(job.get("probe_family") or "")
        probe_payload = probe_results.get(site_id) or {"ok": False, "error": "missing_probe_result"}

        try:
            if not bool(probe_payload.get("ok")):
                raise RuntimeError(str(probe_payload.get("error") or "probe_failed"))

            result = probe_payload.get("result")
            if not isinstance(result, dict):
                raise RuntimeError("probe_result_invalid")

            if probe_family == "http":
                ssl_result = probe_payload.get("ssl_result")
                if isinstance(ssl_result, dict):
                    insert_ssl_check(db, site_id, ssl_result, ssl_table_sql)
            elif probe_family not in {"icmp", "tcp"}:
                continue

            insert_probe_result(db, site_id, probe_type_raw, result, probes_table_sql, local_source_node)
            status_log = str(result.get("status"))
            if probe_family == "http":
                redirect_label = "followed" if bool(result.get("follow_redirects")) else "blocked"
                status_log = (
                    f"{status_log}; method={result.get('http_method')}; redirect={redirect_label}; "
                    f"redirected={1 if bool(result.get('redirected')) else 0}; "
                    f"matrice={int(result.get('matrix_online') or 0)}/{int(result.get('matrix_total') or 0)}"
                )
            log_probe_check(monitoring_root / "logs", site_id, site_url, probe_type_raw, status_log)

            if not shadow_mode:
                site_under_maintenance = maintenance_global_active or (site_id in maintenance_site_ids)
                if site_under_maintenance:
                    under_maintenance += 1
                incident_closed_now = False
                if site_under_maintenance:
                    if _close_incident_if_needed(
                        db,
                        cfg,
                        site_id,
                        site_url,
                        result,
                        monitoring_root,
                        logger,
                        incidents_table_sql,
                        send_notifications=send_notifications,
                        notification_batch=notification_batch,
                    ):
                        closed += 1
                        incident_closed_now = True
                    _alert_site(
                        db,
                        cfg,
                        site_id,
                        site_url,
                        "online",
                        alert_table_sql=alert_table_sql,
                        probes_table_sql=probes_table_sql,
                        logger=logger,
                        send_notifications=send_notifications,
                        suppress_recovery_alert=True,
                        notification_batch=notification_batch,
                    )
                else:
                    if _open_incident_if_needed(
                        db,
                        cfg,
                        site_id,
                        site_url,
                        result,
                        logger,
                        probes_table_sql,
                        incidents_table_sql,
                        send_notifications=send_notifications,
                        notification_batch=notification_batch,
                    ):
                        opened += 1
                    if _close_incident_if_needed(
                        db,
                        cfg,
                        site_id,
                        site_url,
                        result,
                        monitoring_root,
                        logger,
                        incidents_table_sql,
                        send_notifications=send_notifications,
                        notification_batch=notification_batch,
                    ):
                        closed += 1
                        incident_closed_now = True

                    _alert_site(
                        db,
                        cfg,
                        site_id,
                        site_url,
                        str(result.get("status")),
                        alert_table_sql=alert_table_sql,
                        probes_table_sql=probes_table_sql,
                        logger=logger,
                        send_notifications=send_notifications,
                        suppress_recovery_alert=incident_closed_now,
                        notification_batch=notification_batch,
                    )
            processed += 1
        except Exception as exc:
            logger.error("Site check failed for %s: %s", site_url, exc)
            errors += 1

    if notification_batch is not None:
        notification_batch.flush(db, notification_targets, cfg, logger)

    escalation_stats = {"triggered": 0, "sent": 0, "failed": 0, "skipped": 0, "placeholder": 0}
    if escalation_enabled:
        try:
            escalation_stats = apply_escalation_policy(
                db,
                cfg,
                incidents_table_sql,
                notification_targets,
                logger,
            )
        except Exception as exc:
            logger.warning("Escalation policy execution failed: %s", exc)

    return {
        "ok": True,
        "sites_checked": processed,
        "errors": errors,
        "incidents_opened": opened,
        "incidents_closed": closed,
        "sites_under_maintenance": under_maintenance,
        "sites_skipped_scheduler": skipped_scheduler,
        "scheduler": {
            "now_unix": now_ts,
            "force_run": force_run,
            "tolerance_sec": tolerance_sec,
            "concurrency": concurrency,
            "http_interval_sec": http_interval_sec,
            "icmp_interval_sec": icmp_interval_sec,
            "http_methods": http_methods,
            "http_redirect_modes": http_redirect_modes,
        },
        "tables": {
            "probes": probes_table,
            "ssl_checks": ssl_table,
            "incidents": incidents_table,
            "alert": alert_table,
        },
        "shadow_mode": shadow_mode,
        "escalation": escalation_stats,
    }
