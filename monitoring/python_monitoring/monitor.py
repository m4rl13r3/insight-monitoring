from __future__ import annotations

import http.cookiejar
import base64
import calendar
import hashlib
import json
import logging
import os
import re
import socket
import ssl
import subprocess
import tempfile
import time
from concurrent.futures import ThreadPoolExecutor, as_completed
from datetime import datetime, timedelta
from pathlib import Path
from typing import Any, Dict, List, Optional, Set, Tuple
from urllib.error import HTTPError, URLError
from urllib.parse import urlparse
from urllib.request import HTTPCookieProcessor, HTTPRedirectHandler, HTTPSHandler, Request, build_opener

from .alerts import send_email_smtp, send_sms
from .advanced_probes import (
    check_browser_status,
    check_docker_status,
    check_grpc_status,
    check_mqtt_status,
    check_rabbitmq_status,
    check_redis_status,
    check_smtp_status,
    check_snmp_status,
    check_sql_status,
    check_websocket_status,
    collect_network_diagnostics,
)
from .db import Database
from .notifications import decrypt_config, dispatch_event, has_notification_channels
from .oncall import apply_oncall_escalations
from .reinforced import activate_reinforced_watch, effective_interval, load_active_watches
from .table_names import is_truthy, table_name, table_sql


SUPPORTED_INTERVALS_SEC: Tuple[int, ...] = (10, 20, 30, 60, 120, 180, 300, 600, 1800, 21600, 43200, 86400)
SUPPORTED_HTTP_METHODS: Tuple[str, ...] = ("GET", "POST", "PUT", "HEAD", "DELETE", "PATCH", "OPTIONS")
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
    return ["GET"]


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
    return ["follow"]


def _redirect_mode_to_bool(mode: str) -> bool:
    return str(mode or "").strip().lower() == "follow"


def _is_interval_due(now_ts: int, interval_sec: int, tolerance_sec: int, force_run: bool) -> bool:
    if force_run:
        return True
    if interval_sec <= 0:
        return False
    mod = now_ts % interval_sec
    return mod <= tolerance_sec or (interval_sec - mod) <= tolerance_sec


def _parse_status_ranges(raw: Any) -> List[Tuple[int, int]]:
    ranges: List[Tuple[int, int]] = []
    for token in str(raw or "200-399").replace(";", ",").split(","):
        value = token.strip()
        if not value:
            continue
        if "-" in value:
            start_raw, end_raw = value.split("-", 1)
        else:
            start_raw = value
            end_raw = value
        try:
            start = int(start_raw)
            end = int(end_raw)
        except ValueError:
            continue
        if 100 <= start <= end <= 599:
            ranges.append((start, end))
    return ranges or [(200, 399)]


def _http_code_is_online(http_code: int, accepted: Any = "200-399") -> bool:
    return any(start <= http_code <= end for start, end in _parse_status_ranges(accepted))


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
        "name": "VARCHAR(160) NULL",
        "active": "TINYINT(1) NOT NULL DEFAULT 1",
        "probe_interval_sec": "INT NOT NULL DEFAULT 60",
        "timeout_sec": "SMALLINT UNSIGNED NOT NULL DEFAULT 10",
        "retry_count": "TINYINT UNSIGNED NOT NULL DEFAULT 2",
        "failure_threshold": "TINYINT UNSIGNED NOT NULL DEFAULT 2",
        "recovery_threshold": "TINYINT UNSIGNED NOT NULL DEFAULT 2",
        "calc_method": "VARCHAR(24) NOT NULL DEFAULT 'inherit'",
        "http_methods": "VARCHAR(128) NOT NULL DEFAULT 'GET'",
        "http_redirect_modes": "VARCHAR(32) NOT NULL DEFAULT 'follow'",
        "http_primary_method": "VARCHAR(16) NOT NULL DEFAULT 'GET'",
        "http_primary_redirect": "VARCHAR(16) NOT NULL DEFAULT 'follow'",
        "accepted_status_codes": "VARCHAR(255) NOT NULL DEFAULT '200-399'",
        "keyword_text": "TEXT NULL",
        "keyword_mode": "ENUM('none','contains','absent') NOT NULL DEFAULT 'none'",
        "json_path": "VARCHAR(500) NULL",
        "json_expected_value": "TEXT NULL",
        "request_headers_json": "TEXT NULL",
        "request_body": "MEDIUMTEXT NULL",
        "basic_auth_username": "VARCHAR(255) NULL",
        "basic_auth_password_ciphertext": "TEXT NULL",
        "probe_config_ciphertext": "LONGTEXT NULL",
        "browser_script": "MEDIUMTEXT NULL",
        "diagnostics_enabled": "TINYINT(1) NOT NULL DEFAULT 1",
        "diagnostic_capture_body": "TINYINT(1) NOT NULL DEFAULT 0",
        "tls_verify": "TINYINT(1) NOT NULL DEFAULT 1",
        "tls_expiry_threshold_days": "SMALLINT UNSIGNED NOT NULL DEFAULT 14",
        "dns_record_type": "VARCHAR(12) NOT NULL DEFAULT 'A'",
        "dns_expected_value": "VARCHAR(500) NULL",
        "heartbeat_token_hash": "CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NULL",
        "heartbeat_grace_sec": "INT UNSIGNED NOT NULL DEFAULT 300",
        "slo_target_percent": "DECIMAL(7,4) NOT NULL DEFAULT 99.9000",
        "public_visible": "TINYINT(1) NOT NULL DEFAULT 1",
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


def ensure_monitoring_check_state_table(db: Database) -> None:
    db.execute(
        """
        CREATE TABLE IF NOT EXISTS monitoring_check_state (
            site_id INT NOT NULL,
            effective_status ENUM('online','offline','degraded','unknown','paused') NOT NULL DEFAULT 'unknown',
            consecutive_failures SMALLINT UNSIGNED NOT NULL DEFAULT 0,
            consecutive_successes SMALLINT UNSIGNED NOT NULL DEFAULT 0,
            last_raw_status ENUM('online','offline','degraded','unknown') NOT NULL DEFAULT 'unknown',
            last_error VARCHAR(500) NULL,
            last_change_at DATETIME(3) NULL,
            last_heartbeat_at DATETIME(3) NULL,
            updated_at DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3) ON UPDATE CURRENT_TIMESTAMP(3),
            PRIMARY KEY (site_id),
            KEY idx_monitoring_check_state_status (effective_status, updated_at)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
        """
    )


def ensure_probe_diagnostics_table(db: Database) -> None:
    db.execute(
        """
        CREATE TABLE IF NOT EXISTS probe_diagnostics (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            site_id INT NOT NULL,
            probe_id BIGINT UNSIGNED NULL,
            status ENUM('online','offline','degraded','unknown') NOT NULL DEFAULT 'unknown',
            error_code VARCHAR(120) NULL,
            timing_json JSON NULL,
            response_headers_json JSON NULL,
            body_excerpt TEXT NULL,
            artifact_path VARCHAR(500) NULL,
            network_json JSON NULL,
            created_at DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
            PRIMARY KEY (id),
            KEY idx_probe_diagnostics_site_time (site_id, created_at),
            KEY idx_probe_diagnostics_probe (probe_id),
            CONSTRAINT fk_probe_diagnostics_site FOREIGN KEY (site_id) REFERENCES sites (id) ON DELETE CASCADE,
            CONSTRAINT fk_probe_diagnostics_probe FOREIGN KEY (probe_id) REFERENCES probes (id) ON DELETE SET NULL
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        """
    )


def apply_monitor_thresholds(db: Database, site: Dict[str, Any], result: Dict[str, Any]) -> Dict[str, Any]:
    site_id = int(site.get("id") or 0)
    raw_status = str(result.get("status") or "unknown").strip().lower()
    if raw_status not in {"online", "offline", "degraded", "unknown"}:
        raw_status = "unknown"
    current = db.query_one("SELECT * FROM monitoring_check_state WHERE site_id = %s LIMIT 1", (site_id,)) or {}
    previous = str(current.get("effective_status") or "unknown")
    failures = int(current.get("consecutive_failures") or 0)
    successes = int(current.get("consecutive_successes") or 0)
    failure_threshold = max(1, min(20, _parse_int(site.get("failure_threshold"), 2)))
    recovery_threshold = max(1, min(20, _parse_int(site.get("recovery_threshold"), 2)))
    if raw_status == "online":
        successes += 1
        failures = 0
        effective = "online" if previous in {"unknown", "online", "paused"} or successes >= recovery_threshold else previous
    elif raw_status in {"offline", "degraded"}:
        failures += 1
        successes = 0
        effective = raw_status if failures >= failure_threshold else previous
        if effective == "paused":
            effective = "unknown"
    else:
        failures = 0
        successes = 0
        effective = "unknown" if previous in {"unknown", "paused"} else previous
    changed = effective != previous
    error = str(result.get("error") or "").strip()[:500] or None
    db.execute(
        """
        INSERT INTO monitoring_check_state
            (site_id, effective_status, consecutive_failures, consecutive_successes, last_raw_status, last_error, last_change_at)
        VALUES (%s, %s, %s, %s, %s, %s, IF(%s, CURRENT_TIMESTAMP(3), NULL))
        ON DUPLICATE KEY UPDATE
            effective_status = VALUES(effective_status),
            consecutive_failures = VALUES(consecutive_failures),
            consecutive_successes = VALUES(consecutive_successes),
            last_raw_status = VALUES(last_raw_status),
            last_error = VALUES(last_error),
            last_change_at = IF(%s, CURRENT_TIMESTAMP(3), last_change_at)
        """,
        (site_id, effective, failures, successes, raw_status, error, 1 if changed else 0, 1 if changed else 0),
    )
    normalized = dict(result)
    normalized["raw_status"] = raw_status
    normalized["status"] = effective
    normalized["threshold_pending"] = effective != raw_status
    normalized["status_changed"] = changed
    normalized["consecutive_failures"] = failures
    normalized["consecutive_successes"] = successes
    return normalized


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


def get_active_maintenance_scope(db: Database, site_ids: List[int]) -> tuple[bool, Set[int]]:
    ids = sorted({int(site_id) for site_id in site_ids if int(site_id) > 0})
    if not ids:
        return (False, set())

    placeholders = ", ".join(["%s"] * len(ids))
    rows = db.query_all(
        f"""
        SELECT m.site_id AS legacy_site_id, ms.site_id AS mapped_site_id,
               (SELECT COUNT(*) FROM maintenance_sites scope WHERE scope.maintenance_id = m.id) AS mapped_count
        FROM scheduled_maintenances m
        LEFT JOIN maintenance_sites ms ON ms.maintenance_id = m.id
        WHERE m.status <> 'cancelled'
          AND m.starts_at <= NOW()
          AND m.ends_at >= NOW()
          AND (m.site_id IS NULL OR m.site_id IN ({placeholders}) OR ms.site_id IN ({placeholders}))
        """,
        tuple(ids) + tuple(ids),
    )

    global_active = False
    specific_sites: Set[int] = set()
    for row in rows:
        raw_site_id = row.get("mapped_site_id") if int(row.get("mapped_count") or 0) > 0 else row.get("legacy_site_id")
        if raw_site_id is None and int(row.get("mapped_count") or 0) == 0:
            global_active = True
            continue
        try:
            sid = int(raw_site_id)
        except Exception:
            sid = 0
        if sid > 0:
            specific_sites.add(sid)
    return (global_active, specific_sites)


def _advance_recurrence(value: datetime, recurrence: str, interval: int) -> datetime:
    step = max(1, interval)
    if recurrence == "daily":
        return value + timedelta(days=step)
    if recurrence == "weekly":
        return value + timedelta(weeks=step)
    if recurrence == "monthly":
        month_index = value.year * 12 + value.month - 1 + step
        year = month_index // 12
        month = month_index % 12 + 1
        day = min(value.day, calendar.monthrange(year, month)[1])
        return value.replace(year=year, month=month, day=day)
    return value


def advance_scheduled_maintenances(db: Database, cfg: Dict[str, str], logger: logging.Logger) -> Dict[str, int]:
    stats = {"started": 0, "completed": 0, "advanced": 0}
    rows = db.query_all(
        """
        SELECT m.*, GROUP_CONCAT(ms.site_id ORDER BY ms.site_id SEPARATOR ',') AS site_ids_csv
        FROM scheduled_maintenances m
        LEFT JOIN maintenance_sites ms ON ms.maintenance_id = m.id
        WHERE m.status = 'planned'
        GROUP BY m.id
        ORDER BY m.starts_at, m.id
        """
    )
    now = datetime.now()
    for row in rows:
        maintenance_id = int(row.get("id") or 0)
        starts_at = _parse_datetime(row.get("starts_at"))
        ends_at = _parse_datetime(row.get("ends_at"))
        if maintenance_id <= 0 or starts_at is None or ends_at is None:
            continue
        site_ids = [int(value) for value in str(row.get("site_ids_csv") or "").split(",") if value.isdigit()]
        if not site_ids and row.get("site_id") is not None:
            site_ids = [int(row.get("site_id") or 0)]
        targets = db.query_all(
            "SELECT id, url FROM sites WHERE id IN (" + ",".join(["%s"] * len(site_ids)) + ")",
            tuple(site_ids),
        ) if site_ids else []
        if starts_at <= now <= ends_at and _parse_datetime(row.get("last_occurrence_at")) != starts_at:
            db.execute("UPDATE scheduled_maintenances SET last_occurrence_at = starts_at WHERE id = %s", (maintenance_id,))
            stats["started"] += 1
            contexts = targets or [{"id": 0, "url": "all services"}]
            for target in contexts:
                try:
                    dispatch_event(
                        db,
                        cfg,
                        "maintenance_started",
                        {"maintenance_id": maintenance_id, "site_id": int(target.get("id") or 0), "site_url": str(target.get("url") or "all services"), "sites": str(target.get("url") or "all services"), "domain": _extract_host(str(target.get("url") or "")) or "maintenance", "severity": "info", "message": str(row.get("title") or ""), "notify_subscribers": bool(row.get("notify_public"))},
                        idempotency_key=f"maintenance:{maintenance_id}:start:{starts_at.isoformat()}",
                    )
                except Exception as exc:
                    logger.warning("Maintenance start notification failed: %s", exc)
        if ends_at >= now:
            continue
        contexts = targets or [{"id": 0, "url": "all services"}]
        for target in contexts:
            try:
                dispatch_event(
                    db,
                    cfg,
                    "maintenance_ended",
                    {"maintenance_id": maintenance_id, "site_id": int(target.get("id") or 0), "site_url": str(target.get("url") or "all services"), "sites": str(target.get("url") or "all services"), "domain": _extract_host(str(target.get("url") or "")) or "maintenance", "severity": "info", "message": str(row.get("title") or ""), "notify_subscribers": bool(row.get("notify_public"))},
                    idempotency_key=f"maintenance:{maintenance_id}:end:{ends_at.isoformat()}",
                )
            except Exception as exc:
                logger.warning("Maintenance end notification failed: %s", exc)
        recurrence = str(row.get("recurrence") or "none")
        recurrence_until = _parse_datetime(row.get("recurrence_until"))
        interval = max(1, _parse_int(row.get("recurrence_interval"), 1))
        next_start = starts_at
        next_end = ends_at
        if recurrence != "none":
            while next_end < now:
                next_start = _advance_recurrence(next_start, recurrence, interval)
                next_end = _advance_recurrence(next_end, recurrence, interval)
            if recurrence_until is None or next_start <= recurrence_until:
                db.execute(
                    "UPDATE scheduled_maintenances SET starts_at = %s, ends_at = %s, last_occurrence_at = NULL WHERE id = %s",
                    (next_start.strftime("%Y-%m-%d %H:%M:%S"), next_end.strftime("%Y-%m-%d %H:%M:%S"), maintenance_id),
                )
                stats["advanced"] += 1
                continue
        db.execute("UPDATE scheduled_maintenances SET status = 'completed' WHERE id = %s", (maintenance_id,))
        stats["completed"] += 1
    return stats


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


def _json_path_value(payload: Any, path: str) -> tuple[bool, Any]:
    normalized = str(path or "").strip()
    if normalized in {"", "$"}:
        return (True, payload)
    if normalized.startswith("$."):
        normalized = normalized[2:]
    elif normalized.startswith("$"):
        normalized = normalized[1:]
    tokens = [token for token in normalized.replace("[", ".").replace("]", "").split(".") if token]
    current = payload
    for token in tokens:
        if isinstance(current, dict) and token in current:
            current = current[token]
            continue
        if isinstance(current, list) and token.isdigit() and int(token) < len(current):
            current = current[int(token)]
            continue
        return (False, None)
    return (True, current)


def _http_headers(cfg: Dict[str, Any]) -> Dict[str, str]:
    headers = {"User-Agent": "Insight-Monitor/1.0", "Accept": "*/*"}
    configured = cfg.get("request_headers_json")
    if isinstance(configured, str) and configured.strip():
        try:
            configured = json.loads(configured)
        except json.JSONDecodeError:
            configured = {}
    if isinstance(configured, dict):
        for key, value in configured.items():
            name = str(key).strip()
            content = str(value)
            if name and "\r" not in name and "\n" not in name and "\r" not in content and "\n" not in content:
                headers[name] = content
    username = str(cfg.get("basic_auth_username") or "").strip()
    ciphertext = str(cfg.get("basic_auth_password_ciphertext") or "").strip()
    password = str(cfg.get("basic_auth_password") or "")
    if username and ciphertext and not password:
        try:
            password = str(decrypt_config(ciphertext).get("password") or "")
        except Exception:
            password = ""
    if username and password:
        token = base64.b64encode(f"{username}:{password}".encode("utf-8")).decode("ascii")
        headers["Authorization"] = f"Basic {token}"
    return headers


def _redact_diagnostic_text(value: str) -> str:
    text = str(value or "")[:20000]
    patterns = (
        r"(?i)(authorization\s*[:=]\s*)([^\s,;]+)",
        r"(?i)((?:password|passwd|secret|token|api[_-]?key)\s*[:=]\s*[\"']?)([^\s,;\"']+)",
        r"(?i)(bearer\s+)([a-z0-9._~+\-/=]+)",
    )
    for pattern in patterns:
        text = re.sub(pattern, r"\1[redacted]", text)
    return text


def _http_assertions(body: bytes, cfg: Dict[str, Any]) -> tuple[bool, str]:
    text = body.decode("utf-8", errors="replace")
    keyword = str(cfg.get("keyword_text") or "")
    mode = str(cfg.get("keyword_mode") or "none").strip().lower()
    if mode == "contains" and keyword not in text:
        return (False, "keyword_missing")
    if mode == "absent" and keyword and keyword in text:
        return (False, "keyword_present")
    json_path = str(cfg.get("json_path") or "").strip()
    if json_path:
        try:
            payload = json.loads(text)
        except json.JSONDecodeError:
            return (False, "invalid_json")
        found, value = _json_path_value(payload, json_path)
        if not found:
            return (False, "json_path_missing")
        expected = str(cfg.get("json_expected_value") or "")
        if expected:
            serialized = json.dumps(value, ensure_ascii=False, separators=(",", ":")) if isinstance(value, (dict, list, bool)) or value is None else str(value)
            if serialized != expected:
                return (False, "json_value_mismatch")
    return (True, "")


def _http_probe(url: str, cfg: Dict[str, Any], cookie_jar: http.cookiejar.CookieJar, *, method: str, follow_redirects: bool) -> Dict[str, Any]:
    verify_tls = is_truthy(cfg.get("tls_verify", "1"))
    context = ssl.create_default_context() if verify_tls else ssl._create_unverified_context()
    handlers: List[Any] = [HTTPCookieProcessor(cookie_jar), HTTPSHandler(context=context)]
    if not follow_redirects:
        handlers.append(NoRedirectHandler())
    opener = build_opener(*handlers)
    headers = _http_headers(cfg)
    request_body = str(cfg.get("request_body") or "")
    data = request_body.encode("utf-8") if request_body and method not in {"GET", "HEAD"} else None
    req = Request(url, data=data, headers=headers, method=method)
    start = time.perf_counter()
    http_code = 0
    redirected = False
    final_url = url
    body = b""
    response_headers: Dict[str, str] = {}
    error = ""
    try:
        with opener.open(req, timeout=max(1, min(120, _parse_int(cfg.get("timeout_sec"), 10)))) as response:
            http_code = int(response.getcode() or 0)
            final_url = str(response.geturl() or url)
            redirected = final_url.rstrip("/") != url.rstrip("/")
            response_headers = {str(key): str(value)[:2000] for key, value in response.headers.items() if str(key).lower() not in {"set-cookie", "authorization", "proxy-authorization"}}
            body = response.read(1_000_001)
    except HTTPError as exc:
        http_code = int(getattr(exc, "code", 0) or 0)
        final_url = str(getattr(exc, "url", "") or url)
        redirected = final_url.rstrip("/") != url.rstrip("/")
        response_headers = {str(key): str(value)[:2000] for key, value in exc.headers.items() if str(key).lower() not in {"set-cookie", "authorization", "proxy-authorization"}}
        try:
            body = exc.read(1_000_001)
        except Exception:
            body = b""
        error = f"http_{http_code}"
    except (URLError, TimeoutError, OSError) as exc:
        error = str(getattr(exc, "reason", exc) or "connection_failed")[:500]
    except Exception as exc:
        error = str(exc or "request_failed")[:500]
    elapsed_ms = round((time.perf_counter() - start) * 1000, 2)
    accepted = _http_code_is_online(http_code, cfg.get("accepted_status_codes"))
    assertions_ok, assertion_error = _http_assertions(body[:1_000_000], cfg) if accepted else (True, "")
    online = accepted and assertions_ok
    if assertion_error:
        error = assertion_error
    excerpt = ""
    if is_truthy(cfg.get("diagnostic_capture_body")) and body:
        content_type = next((value for key, value in response_headers.items() if key.lower() == "content-type"), "")
        if "text" in content_type.lower() or "json" in content_type.lower() or not content_type:
            excerpt = _redact_diagnostic_text(body[:20000].decode("utf-8", errors="replace"))
    return {
        "status": "online" if online else "offline",
        "response_time": elapsed_ms,
        "http_code": http_code,
        "method": method,
        "follow_redirects": bool(follow_redirects),
        "redirected": bool(redirected),
        "final_url": final_url,
        "error": error,
        "body_truncated": len(body) > 1_000_000,
        "assertions_ok": assertions_ok,
        "diagnostic": {
            "timing": {"total_ms": elapsed_ms},
            "response_headers": response_headers,
            "body_excerpt": excerpt,
            "body_sha256": hashlib.sha256(body).hexdigest() if body else "",
            "final_url": final_url,
        },
    }


def _run_http_variant(
    url: str,
    method: str,
    follow_redirects: bool,
    cookie_jar: http.cookiejar.CookieJar,
    cfg: Dict[str, Any],
) -> Dict[str, Any]:
    attempts = max(1, min(11, _parse_int(cfg.get("retry_count"), 0) + 1))
    result: Dict[str, Any] = {}
    for attempt in range(attempts):
        result = _http_probe(url, cfg, cookie_jar, method=method, follow_redirects=follow_redirects)
        result["attempts"] = attempt + 1
        if result.get("status") == "online":
            break
        if attempt + 1 < attempts:
            time.sleep(min(1.0, 0.15 * (attempt + 1)))
    return result


def check_http_status(url: str, cfg: Dict[str, Any]) -> Dict[str, Any]:
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
                    cfg=cfg,
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
        "error": str(primary.get("error") or ""),
        "attempts": int(primary.get("attempts") or 1),
        "assertions_ok": bool(primary.get("assertions_ok", True)),
        "diagnostic": primary.get("diagnostic") if isinstance(primary.get("diagnostic"), dict) else {},
    }


def check_icmp_status(host: str, timeout_sec: int = 3) -> Dict[str, Any]:
    start = time.perf_counter()
    status_code = 1
    try:
        completed = subprocess.run(
            ["ping", "-c", "1", "-W", str(max(1, min(120, timeout_sec))), host],
            check=False,
            stdout=subprocess.DEVNULL,
            stderr=subprocess.DEVNULL,
            timeout=max(2, min(125, timeout_sec + 2)),
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


def check_tcp_status(host: str, port: int, timeout_sec: int = 3) -> Dict[str, Any]:
    start = time.perf_counter()
    online = False
    try:
        with socket.create_connection((host, port), timeout=max(1, min(120, timeout_sec))):
            online = True
    except (OSError, ValueError):
        online = False

    elapsed_ms = round((time.perf_counter() - start) * 1000, 2)
    return {
        "status": "online" if online else "offline",
        "response_time": elapsed_ms if online else None,
        "http_code": None,
    }


def check_dns_status(host: str, record_type: str = "A", expected_value: str = "", timeout_sec: int = 5) -> Dict[str, Any]:
    start = time.perf_counter()
    records: List[str] = []
    error = ""
    try:
        import dns.resolver

        resolver = dns.resolver.Resolver()
        resolver.timeout = max(1, min(120, timeout_sec))
        resolver.lifetime = max(1, min(120, timeout_sec))
        answer = resolver.resolve(host, record_type)
        records = sorted({str(item).strip().rstrip(".") for item in answer if str(item).strip()})
    except Exception as exc:
        error = str(exc)[:500]
    expected = str(expected_value or "").strip().rstrip(".")
    online = bool(records) and (not expected or expected in records)
    if records and expected and expected not in records:
        error = "dns_value_mismatch"
    elapsed_ms = round((time.perf_counter() - start) * 1000, 2)
    return {
        "status": "online" if online else "offline",
        "response_time": elapsed_ms,
        "http_code": None,
        "records": records,
        "error": error,
    }


def check_heartbeat_status(last_heartbeat_at: Any, grace_sec: int) -> Dict[str, Any]:
    heartbeat = _parse_datetime(last_heartbeat_at)
    if heartbeat is None:
        return {"status": "offline", "response_time": None, "http_code": None, "error": "heartbeat_missing"}
    age_sec = max(0, int((datetime.now() - heartbeat).total_seconds()))
    online = age_sec <= max(10, grace_sec)
    return {
        "status": "online" if online else "offline",
        "response_time": None,
        "http_code": None,
        "heartbeat_age_sec": age_sec,
        "error": "" if online else "heartbeat_expired",
    }


def insert_probe_result(
    db: Database,
    site_id: int,
    probe_type: str,
    result: Dict[str, Any],
    probes_table_sql: str,
    source_node: str,
) -> int:
    status = result.get("status")
    response_time = result.get("response_time") if status == "online" else None
    http_code = result.get("http_code")
    source_probe_id = int(time.time_ns() // 1000)

    try:
        probe_id = db.insert(
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
        return probe_id
    except Exception:
        pass

    return db.insert(
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


def store_probe_diagnostic(db: Database, site_id: int, probe_id: int, result: Dict[str, Any]) -> int:
    diagnostic = result.get("diagnostic") if isinstance(result.get("diagnostic"), dict) else {}
    timing = diagnostic.get("timing") if isinstance(diagnostic.get("timing"), dict) else {}
    headers = diagnostic.get("response_headers") if isinstance(diagnostic.get("response_headers"), dict) else {}
    network = diagnostic.get("network") if isinstance(diagnostic.get("network"), dict) else {}
    details = {key: value for key, value in diagnostic.items() if key not in {"timing", "response_headers", "body_excerpt", "artifact_path", "network"}}
    if details:
        timing = {**timing, "details": details}
    return db.insert(
        """
        INSERT INTO probe_diagnostics
            (site_id, probe_id, status, error_code, timing_json, response_headers_json, body_excerpt, artifact_path, network_json)
        VALUES (%s, %s, %s, %s, %s, %s, %s, %s, %s)
        """,
        (
            site_id,
            probe_id if probe_id > 0 else None,
            str(result.get("status") or "unknown")[:16],
            str(result.get("error") or "")[:120] or None,
            json.dumps(timing, ensure_ascii=False, separators=(",", ":")) if timing else None,
            json.dumps(headers, ensure_ascii=False, separators=(",", ":")) if headers else None,
            str(diagnostic.get("body_excerpt") or "")[:20000] or None,
            str(diagnostic.get("artifact_path") or "")[:500] or None,
            json.dumps(network, ensure_ascii=False, separators=(",", ":")) if network else None,
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


def _incident_fingerprint(site_url: str, current_result: Dict[str, Any]) -> tuple[str, str]:
    domain = _domain_group_key(site_url)
    probe_type = str(current_result.get("probe_type") or "http").strip().lower()
    error = str(current_result.get("error") or "unavailable").strip().lower()
    error = re.sub(r"https?://\S+", "target", error)
    error = re.sub(r"\b(?:\d{1,3}\.){3}\d{1,3}\b", "address", error)
    error = re.sub(r"\b\d+\b", "number", error)
    error = re.sub(r"[^a-z0-9_:-]+", "-", error).strip("-")[:120] or "unavailable"
    basis = f"{domain}|{probe_type}|{error}"
    return hashlib.sha256(basis.encode("utf-8")).hexdigest(), domain


def _open_incident_group(db: Database, site_url: str, current_result: Dict[str, Any]) -> int:
    fingerprint, domain = _incident_fingerprint(site_url, current_result)
    title = f"{domain} · {str(current_result.get('probe_type') or 'http').upper()}"[:200]
    group_id = db.insert(
        """
        INSERT INTO incident_groups (fingerprint, title, state, occurrence_count, first_seen_at, last_seen_at)
        VALUES (%s, %s, 'open', 1, NOW(), NOW())
        ON DUPLICATE KEY UPDATE
            id = LAST_INSERT_ID(id),
            title = VALUES(title),
            state = 'open',
            occurrence_count = occurrence_count + 1,
            last_seen_at = NOW()
        """,
        (fingerprint, title),
    )
    return int(group_id or 0)


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
    if str(current_result.get("status") or "") != "offline":
        return False

    already_open = db.query_one(
        f"SELECT id FROM {incidents_table_sql} WHERE site_id = %s AND status = 0 LIMIT 1",
        (site_id,),
    )
    if already_open:
        return False

    incident_group_id = _open_incident_group(db, site_url, current_result)
    metadata = {
        "detector": "monitor",
        "probe_type": str(current_result.get("probe_type") or "http"),
        "error_code": str(current_result.get("error") or "")[:500],
        "raw_status": str(current_result.get("raw_status") or current_result.get("status") or "unknown"),
    }
    incident_id = db.insert(
        f"""
        INSERT INTO {incidents_table_sql}
            (incident_group_id, site_id, title, summary, metadata, severity, lifecycle_status, started_at, http_code, postmortem, ai_created, source_mode, site_label, resolved, status, published)
        VALUES (%s, %s, %s, %s, %s, 'major', 'started', NOW(), %s, NULL, 0, 'system', %s, 0, 0, 1)
        """,
        (
            incident_group_id or None,
            site_id,
            f"Service unavailable: {_extract_host(site_url) or site_url}"[:200],
            str(current_result.get("error") or "Automatic monitoring detected an unavailable service.")[:2000],
            json.dumps(metadata, ensure_ascii=False, separators=(",", ":")),
            _sanitize_http_code(current_result.get("http_code")),
            site_url[:255],
        ),
    )
    db.execute("INSERT IGNORE INTO incident_sites (incident_id, site_id) VALUES (%s, %s)", (incident_id, site_id))
    db.execute(
        "INSERT INTO incident_updates (incident_id, lifecycle_status, message, is_public, author_name) VALUES (%s, 'started', %s, 1, 'Insight')",
        (incident_id, "Monitoring detected an interruption. Investigation is in progress."),
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
        SELECT id, incident_group_id
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
        f"UPDATE {incidents_table_sql} SET ended_at = NOW(), resolved = 1, status = 1, lifecycle_status = 'resolved', resolved_by = 'Insight' WHERE id = %s",
        (incident_id,),
    )
    incident_group_id = int(row.get("incident_group_id") or 0)
    if incident_group_id > 0:
        remaining = db.query_one(
            f"SELECT COUNT(*) AS open_count FROM {incidents_table_sql} WHERE incident_group_id = %s AND status = 0 AND id <> %s",
            (incident_group_id, incident_id),
        )
        if int((remaining or {}).get("open_count") or 0) == 0:
            db.execute("UPDATE incident_groups SET state = 'resolved', last_seen_at = NOW() WHERE id = %s", (incident_group_id,))
    db.execute(
        "INSERT INTO incident_updates (incident_id, lifecycle_status, message, is_public, author_name) VALUES (%s, 'resolved', %s, 1, 'Insight')",
        (incident_id, "The service is operational again. Enhanced monitoring is active."),
    )

    reinforced = activate_reinforced_watch(db, site_id, incident_id, "local", cfg)

    info = db.query_one(
        f"SELECT started_at, ended_at, http_code FROM {incidents_table_sql} WHERE id = %s",
        (incident_id,),
    )

    started_at = _parse_datetime(info.get("started_at") if info else None)
    ended_at = _parse_datetime(info.get("ended_at") if info else None)
    http_code = _sanitize_http_code(info.get("http_code") if info else None)

    pm_text, timed_out = _generate_postmortem(monitoring_root, site_url, started_at, ended_at, http_code)
    if reinforced.get("active"):
        duration_minutes = max(1, int(reinforced["duration_sec"]) // 60)
        pm_text += (
            f" Enhanced monitoring is active for {duration_minutes} minutes "
            f"with checks every {int(reinforced['interval_sec'])} seconds."
        )

    safe_pm_text = _sanitize_postmortem_text(pm_text)
    if timed_out or not safe_pm_text:
        db.execute(
            f"UPDATE {incidents_table_sql} SET postmortem = NULL, ai_created = 0 WHERE id = %s",
            (incident_id,),
        )
    else:
        db.execute(
            f"UPDATE {incidents_table_sql} SET postmortem = %s, ai_created = 0 WHERE id = %s",
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

    if status != "online":
        if not alert_sent_previously:
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


def _retry_simple_probe(callback: Any, retries: int) -> Dict[str, Any]:
    result: Dict[str, Any] = {}
    for attempt in range(max(1, min(11, retries + 1))):
        result = callback()
        result["attempts"] = attempt + 1
        if result.get("status") == "online":
            break
        if attempt < retries:
            time.sleep(min(1.0, 0.15 * (attempt + 1)))
    return result


def _run_site_probe_task(job: Dict[str, Any]) -> Dict[str, Any]:
    probe_family = str(job.get("probe_family") or "")
    probe_type_raw = str(job.get("probe_type_raw") or "http")
    site_url = str(job.get("site_url") or "")
    timeout_sec = max(1, min(120, _parse_int(job.get("timeout_sec"), 10)))
    retry_count = max(0, min(10, _parse_int(job.get("retry_count"), 2)))

    if probe_family == "http":
        site_http_cfg = job.get("site_http_cfg")
        if not isinstance(site_http_cfg, dict):
            site_http_cfg = {}
        result = check_http_status(site_url, site_http_cfg)
        ssl_result = check_ssl_certificate(site_url)
        if result.get("status") == "online" and isinstance(ssl_result, dict):
            days_remaining = ssl_result.get("days_remaining")
            threshold = max(1, min(365, _parse_int(job.get("tls_expiry_threshold_days"), 14)))
            if ssl_result.get("is_valid") is False and is_truthy(job.get("tls_verify", "1")):
                result["status"] = "offline"
                result["error"] = "tls_invalid"
            elif isinstance(days_remaining, int) and days_remaining <= threshold:
                result["status"] = "degraded"
                result["error"] = "tls_expiring"
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
        result = _retry_simple_probe(lambda: check_icmp_status(host, timeout_sec), retry_count)
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
        result = _retry_simple_probe(lambda: check_tcp_status(host, port, timeout_sec), retry_count)
        return {
            "ok": True,
            "probe_family": "tcp",
            "probe_type_raw": probe_type_raw,
            "result": result,
            "ssl_result": None,
        }

    if probe_family == "dns":
        result = _retry_simple_probe(
            lambda: check_dns_status(
                site_url,
                str(job.get("dns_record_type") or "A"),
                str(job.get("dns_expected_value") or ""),
                timeout_sec,
            ),
            retry_count,
        )
        return {"ok": True, "probe_family": "dns", "probe_type_raw": probe_type_raw, "result": result, "ssl_result": None}

    if probe_family == "heartbeat":
        result = check_heartbeat_status(job.get("last_heartbeat_at"), max(10, _parse_int(job.get("heartbeat_grace_sec"), 300)))
        return {"ok": True, "probe_family": "heartbeat", "probe_type_raw": probe_type_raw, "result": result, "ssl_result": None}

    if probe_family in {"browser", "websocket", "mqtt", "sql", "docker", "grpc", "redis", "smtp", "rabbitmq", "snmp"}:
        probe_cfg = job.get("site_probe_cfg")
        if not isinstance(probe_cfg, dict):
            probe_cfg = {}
        callbacks = {
            "browser": check_browser_status,
            "websocket": check_websocket_status,
            "mqtt": check_mqtt_status,
            "sql": check_sql_status,
            "docker": check_docker_status,
            "grpc": check_grpc_status,
            "redis": check_redis_status,
            "smtp": check_smtp_status,
            "rabbitmq": check_rabbitmq_status,
            "snmp": check_snmp_status,
        }
        result = _retry_simple_probe(lambda: callbacks[probe_family](site_url, probe_cfg), retry_count)
        return {"ok": True, "probe_family": probe_family, "probe_type_raw": probe_type_raw, "result": result, "ssl_result": None}

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
    if probe not in {"http", "ping", "icmp", "tcp", "dns"}:
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

    if probe == "dns":
        result = check_dns_status(url, str(cfg.get("dns_record_type") or "A"), str(cfg.get("dns_expected_value") or ""), _parse_int(cfg.get("timeout_sec"), 5))
        return {"ok": True, "url": url, "probe_type": "dns", "result": result}

    result = check_http_status(url, cfg)
    return {
        "ok": True,
        "url": url,
        "probe_type": "http",
        "result": result,
    }


def run_monitor_job(db: Database, cfg: Dict[str, str], monitoring_root: Path) -> Dict[str, Any]:
    logger = _setup_logger(monitoring_root / "logs", logger_key="main")
    send_notifications = not is_truthy(cfg.get("disable_notifications"))
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

    sites_table_sql = table_sql("sites")
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
    ensure_monitoring_check_state_table(db)
    ensure_probe_diagnostics_table(db)
    try:
        ensure_scheduled_maintenances_table(db)
    except Exception as exc:
        logger.warning("Unable to ensure scheduled_maintenances table: %s", exc)
    maintenance_scheduler_stats = {"started": 0, "completed": 0, "advanced": 0}
    try:
        maintenance_scheduler_stats = advance_scheduled_maintenances(db, cfg, logger)
    except Exception as exc:
        logger.warning("Unable to advance scheduled maintenances: %s", exc)

    sites = db.query_all(
        f"""
        SELECT
            id,
            url,
            name,
            probe_type,
            active,
            probe_interval_sec,
            timeout_sec,
            retry_count,
            failure_threshold,
            recovery_threshold,
            calc_method,
            http_methods,
            http_redirect_modes,
            http_primary_method,
            http_primary_redirect,
            accepted_status_codes,
            keyword_text,
            keyword_mode,
            json_path,
            json_expected_value,
            request_headers_json,
            request_body,
            basic_auth_username,
            basic_auth_password_ciphertext,
            probe_config_ciphertext,
            browser_script,
            diagnostics_enabled,
            diagnostic_capture_body,
            tls_verify,
            tls_expiry_threshold_days,
            dns_record_type,
            dns_expected_value,
            heartbeat_grace_sec,
            state.last_heartbeat_at
        FROM {sites_table_sql} sites
        LEFT JOIN monitoring_check_state state ON state.site_id = sites.id
        """
    )
    site_ids = [int(site.get("id") or 0) for site in sites if int(site.get("id") or 0) > 0]
    active_reinforced_watches = load_active_watches(db, site_ids)
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

        if int(site.get("active") or 0) != 1:
            db.execute(
                """
                INSERT INTO monitoring_check_state (site_id, effective_status, last_raw_status)
                VALUES (%s, 'paused', 'unknown')
                ON DUPLICATE KEY UPDATE effective_status = 'paused', consecutive_failures = 0, consecutive_successes = 0
                """,
                (site_id,),
            )
            skipped_scheduler += 1
            continue

        probe_family = ""
        if probe_type_raw == "http":
            probe_family = "http"
        elif probe_type_raw == "browser":
            probe_family = "browser"
        elif probe_type_raw == "websocket":
            probe_family = "websocket"
        elif probe_type_raw in {"ping", "icmp"}:
            probe_family = "icmp"
        elif probe_type_raw == "tcp":
            probe_family = "tcp"
        elif probe_type_raw == "dns":
            probe_family = "dns"
        elif probe_type_raw == "heartbeat":
            probe_family = "heartbeat"
        elif probe_type_raw == "mqtt":
            probe_family = "mqtt"
        elif probe_type_raw == "sql":
            probe_family = "sql"
        elif probe_type_raw == "docker":
            probe_family = "docker"
        elif probe_type_raw == "grpc":
            probe_family = "grpc"
        elif probe_type_raw == "redis":
            probe_family = "redis"
        elif probe_type_raw == "smtp":
            probe_family = "smtp"
        elif probe_type_raw == "rabbitmq":
            probe_family = "rabbitmq"
        elif probe_type_raw == "snmp":
            probe_family = "snmp"
        else:
            continue

        site_interval_raw = site.get("probe_interval_sec")
        default_interval = http_interval_sec if probe_family == "http" else icmp_interval_sec
        interval_sec = _normalize_interval(site_interval_raw, default_interval)
        interval_sec = effective_interval(interval_sec, active_reinforced_watches.get(site_id))
        if not _is_interval_due(now_ts, interval_sec, tolerance_sec, force_run):
            skipped_scheduler += 1
            continue

        site_http_cfg: Dict[str, Any] = {}
        if probe_family == "http":
            site_http_cfg = {
                "http_methods": str(site.get("http_methods") or cfg.get("http_methods", "")),
                "http_redirect_modes": str(site.get("http_redirect_modes") or cfg.get("http_redirect_modes", "")),
                "http_primary_method": str(site.get("http_primary_method") or cfg.get("http_primary_method", "GET")),
                "http_primary_redirect": str(site.get("http_primary_redirect") or cfg.get("http_primary_redirect", "follow")),
                "accepted_status_codes": str(site.get("accepted_status_codes") or "200-399"),
                "keyword_text": site.get("keyword_text"),
                "keyword_mode": str(site.get("keyword_mode") or "none"),
                "json_path": site.get("json_path"),
                "json_expected_value": site.get("json_expected_value"),
                "request_headers_json": site.get("request_headers_json"),
                "request_body": site.get("request_body"),
                "basic_auth_username": site.get("basic_auth_username"),
                "basic_auth_password_ciphertext": site.get("basic_auth_password_ciphertext"),
                "tls_verify": str(site.get("tls_verify") if site.get("tls_verify") is not None else 1),
                "timeout_sec": str(site.get("timeout_sec") or 10),
                "retry_count": str(site.get("retry_count") or 0),
                "diagnostic_capture_body": site.get("diagnostic_capture_body"),
            }
        site_probe_cfg: Dict[str, Any] = {
            "site_id": site_id,
            "timeout_sec": int(site.get("timeout_sec") or 10),
            "tls_verify": site.get("tls_verify"),
            "probe_config_ciphertext": site.get("probe_config_ciphertext"),
            "browser_script": site.get("browser_script"),
        }
        due_jobs.append(
            {
                "site_id": site_id,
                "site_url": site_url,
                "probe_type_raw": probe_type_raw,
                "probe_family": probe_family,
                "site_http_cfg": site_http_cfg,
                "site_probe_cfg": site_probe_cfg,
                "timeout_sec": int(site.get("timeout_sec") or 10),
                "retry_count": int(site.get("retry_count") or 0),
                "failure_threshold": int(site.get("failure_threshold") or 2),
                "recovery_threshold": int(site.get("recovery_threshold") or 2),
                "tls_verify": site.get("tls_verify"),
                "tls_expiry_threshold_days": int(site.get("tls_expiry_threshold_days") or 14),
                "dns_record_type": str(site.get("dns_record_type") or "A"),
                "dns_expected_value": str(site.get("dns_expected_value") or ""),
                "heartbeat_grace_sec": int(site.get("heartbeat_grace_sec") or 300),
                "last_heartbeat_at": site.get("last_heartbeat_at"),
                "diagnostics_enabled": bool(int(site.get("diagnostics_enabled") if site.get("diagnostics_enabled") is not None else 1)),
                "site": site,
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
            result = apply_monitor_thresholds(db, dict(job.get("site") or {}), result)
            result["probe_type"] = probe_type_raw

            if probe_family == "http":
                ssl_result = probe_payload.get("ssl_result")
                if isinstance(ssl_result, dict):
                    insert_ssl_check(db, site_id, ssl_result, ssl_table_sql)
            elif probe_family not in {"browser", "websocket", "icmp", "tcp", "dns", "heartbeat", "mqtt", "sql", "docker"}:
                continue

            probe_id = insert_probe_result(db, site_id, probe_type_raw, result, probes_table_sql, local_source_node)
            diagnostics_enabled = bool(job.get("diagnostics_enabled"))
            raw_status = str(result.get("raw_status") or result.get("status") or "unknown")
            diagnostic = result.get("diagnostic") if isinstance(result.get("diagnostic"), dict) else {}
            if diagnostics_enabled and bool(result.get("status_changed")) and str(result.get("status")) in {"offline", "degraded"} and is_truthy(cfg.get("diagnostics_network", "1")):
                host = _extract_host(site_url)
                if host:
                    diagnostic["network"] = collect_network_diagnostics(host, int(job.get("timeout_sec") or 5))
                    result["diagnostic"] = diagnostic
            has_artifact = bool(str(diagnostic.get("artifact_path") or "").strip())
            if diagnostics_enabled and (raw_status != "online" or has_artifact):
                store_probe_diagnostic(db, site_id, probe_id, result)
            status_log = str(result.get("status"))
            if probe_family == "http":
                redirect_label = "followed" if bool(result.get("follow_redirects")) else "blocked"
                status_log = (
                    f"{status_log}; method={result.get('http_method')}; redirect={redirect_label}; "
                    f"redirected={1 if bool(result.get('redirected')) else 0}; "
                    f"matrice={int(result.get('matrix_online') or 0)}/{int(result.get('matrix_total') or 0)}"
                )
            log_probe_check(monitoring_root / "logs", site_id, site_url, probe_type_raw, status_log)

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

    escalation_stats = {"due": 0, "sent": 0, "failed": 0, "without_shift": 0, "disabled": 0}
    try:
        escalation_stats = apply_oncall_escalations(db, cfg)
    except Exception as exc:
        logger.warning("On-call escalation execution failed: %s", exc)

    return {
        "ok": True,
        "sites_checked": processed,
        "errors": errors,
        "incidents_opened": opened,
        "incidents_closed": closed,
        "sites_under_maintenance": under_maintenance,
        "sites_skipped_scheduler": skipped_scheduler,
        "reinforced_sites": len(active_reinforced_watches),
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
        "escalation": escalation_stats,
        "oncall": escalation_stats,
        "maintenances": maintenance_scheduler_stats,
    }
