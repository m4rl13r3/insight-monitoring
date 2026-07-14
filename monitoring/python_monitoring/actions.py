from __future__ import annotations

import ipaddress
import json
import re
import secrets
from typing import Any, Dict
from urllib.parse import urlparse

from .db import Database


SUPPORTED_INTERVALS_SEC = {10, 20, 30, 60, 120, 180, 300, 600, 1800, 21600, 43200, 86400}
SUPPORTED_CALC_METHODS = {"inherit", "legacy", "time_weighted", "sample_ratio", "interval_capped", "strict_sla"}
SUPPORTED_PROBE_TYPES = {"http", "browser", "websocket", "ping", "icmp", "tcp", "dns", "heartbeat", "mqtt", "sql", "docker", "grpc", "redis", "smtp", "rabbitmq", "snmp", "service"}
SUPPORTED_DNS_RECORDS = {"A", "AAAA", "CNAME", "MX", "NS", "TXT"}


def _normalize_probe_type(value: str) -> str:
    probe = (value or "").strip().lower()
    return probe if probe in SUPPORTED_PROBE_TYPES else ""


def _bounded_int(value: Any, default: int, minimum: int, maximum: int) -> int:
    try:
        parsed = int(str(value).strip())
    except Exception:
        parsed = default
    return max(minimum, min(maximum, parsed))


def _truthy(value: Any, default: bool = False) -> bool:
    if value is None or str(value).strip() == "":
        return default
    return str(value).strip().lower() in {"1", "true", "yes", "on"}


def _normalize_interval_sec(value: str) -> int:
    try:
        interval = int(str(value).strip())
    except Exception:
        return 0
    return interval if interval in SUPPORTED_INTERVALS_SEC else 0


def _normalize_calc_method(value: str) -> str:
    method = (value or "").strip().lower()
    return method if method in SUPPORTED_CALC_METHODS else "inherit"


def _normalize_http_methods(value: str) -> str:
    allowed = {"GET", "POST", "PUT", "HEAD", "DELETE", "PATCH", "OPTIONS"}
    methods = []
    for token in str(value or "GET").replace(";", ",").split(","):
        method = token.strip().upper()
        if method in allowed and method not in methods:
            methods.append(method)
    return ",".join(methods or ["GET"])


def _normalize_http_redirect_modes(value: str) -> str:
    modes = []
    for token in str(value or "follow").replace(";", ",").split(","):
        mode = token.strip().lower()
        normalized = "follow" if mode in {"follow", "1", "true", "yes"} else "no_follow" if mode in {"no_follow", "nofollow", "0", "false", "no"} else ""
        if normalized and normalized not in modes:
            modes.append(normalized)
    return ",".join(modes or ["follow"])


def _normalize_http_primary_method(value: str) -> str:
    methods = _normalize_http_methods(value)
    return methods.split(",", 1)[0]


def _normalize_http_primary_redirect(value: str) -> str:
    return _normalize_http_redirect_modes(value).split(",", 1)[0]


def _normalize_status_codes(value: Any) -> str:
    raw = str(value or "200-399").strip()
    accepted = []
    for token in raw.replace(";", ",").split(","):
        token = token.strip()
        if re.fullmatch(r"[1-5]\d\d", token):
            accepted.append(token)
            continue
        match = re.fullmatch(r"([1-5]\d\d)-([1-5]\d\d)", token)
        if match and int(match.group(1)) <= int(match.group(2)):
            accepted.append(token)
    return ",".join(dict.fromkeys(accepted)) if accepted else "200-399"


def _normalize_headers(value: Any) -> str | None:
    if isinstance(value, dict):
        decoded = value
    else:
        raw = str(value or "").strip()
        if not raw:
            return None
        try:
            decoded = json.loads(raw)
        except json.JSONDecodeError as exc:
            raise ValueError("invalid_headers") from exc
    if not isinstance(decoded, dict) or len(decoded) > 50:
        raise ValueError("invalid_headers")
    normalized: Dict[str, str] = {}
    for key, item in decoded.items():
        name = str(key).strip()
        content = str(item)
        if not name or len(name) > 120 or "\n" in name or "\r" in name or "\n" in content or "\r" in content:
            raise ValueError("invalid_headers")
        normalized[name] = content[:4000]
    return json.dumps(normalized, ensure_ascii=False, separators=(",", ":"))


def _ensure_sites_runtime_columns(db: Database) -> None:
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
            "SELECT 1 AS present FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'sites' AND COLUMN_NAME = %s LIMIT 1",
            (column,),
        )
        if not exists:
            db.execute(f"ALTER TABLE `sites` ADD COLUMN {column} {ddl}")


def _normalize_url(value: str, probe_type: str = "http") -> str:
    target = (value or "").strip()
    if not target:
        return ""
    if probe_type == "heartbeat":
        slug = re.sub(r"[^a-z0-9]+", "-", target.lower()).strip("-")[:180]
        return f"heartbeat://{slug}" if slug else ""
    if probe_type in {"icmp", "ping", "dns"}:
        candidate = target[1:-1] if target.startswith("[") and target.endswith("]") else target
        if any(marker in candidate for marker in ("://", "/", "?", "#", "@")) or any(char.isspace() for char in candidate):
            return ""
        try:
            return ipaddress.ip_address(candidate).compressed.lower()
        except ValueError:
            pass
        if ":" in candidate or not re.fullmatch(r"(?=.{1,253}\.?$)(?!-)(?:[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?\.)*(?:[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?)\.?", candidate, re.IGNORECASE):
            return ""
        return candidate.rstrip(".").lower()
    if probe_type == "tcp":
        try:
            parsed = urlparse(target if "://" in target else "//" + target)
            host = parsed.hostname
            port = parsed.port
        except ValueError:
            return ""
        if not host or parsed.username or parsed.password or parsed.path not in {"", "/"} or parsed.query or parsed.fragment or port is None or port < 1 or port > 65535:
            return ""
        normalized_host = f"[{host.lower()}]" if ":" in host else host.lower()
        return f"{normalized_host}:{port}"
    if probe_type == "websocket":
        parsed = urlparse(target if target.lower().startswith(("ws://", "wss://")) else "wss://" + target)
        if parsed.scheme not in {"ws", "wss"} or not parsed.hostname or parsed.username or parsed.password or parsed.fragment:
            return ""
        return parsed.geturl()
    if probe_type == "mqtt":
        parsed = urlparse(target if target.lower().startswith(("mqtt://", "mqtts://")) else "mqtt://" + target)
        if parsed.scheme not in {"mqtt", "mqtts"} or not parsed.hostname or parsed.username or parsed.password or parsed.fragment:
            return ""
        try:
            port = parsed.port
        except ValueError:
            return ""
        if port is not None and not 1 <= port <= 65535:
            return ""
        return parsed.geturl()
    if probe_type == "sql":
        parsed = urlparse(target)
        if parsed.scheme not in {"mysql", "mariadb", "postgres", "postgresql"} or not parsed.hostname or not parsed.path.strip("/") or parsed.username or parsed.password or parsed.query or parsed.fragment:
            return ""
        try:
            port = parsed.port
        except ValueError:
            return ""
        if port is not None and not 1 <= port <= 65535:
            return ""
        return parsed.geturl()
    if probe_type == "docker":
        parsed = urlparse(target if target.lower().startswith("docker://") else "docker://" + target)
        if parsed.scheme != "docker" or not parsed.hostname or not parsed.path.strip("/") or parsed.username or parsed.password or parsed.query or parsed.fragment:
            return ""
        try:
            port = parsed.port
        except ValueError:
            return ""
        if parsed.hostname not in {"local", "socket"} and (port is None or not 1 <= port <= 65535):
            return ""
        return parsed.geturl()
    if probe_type == "grpc":
        parsed = urlparse(target if target.lower().startswith(("grpc://", "grpcs://")) else "grpc://" + target)
        if parsed.scheme not in {"grpc", "grpcs"} or not parsed.hostname or parsed.username or parsed.password or parsed.path not in {"", "/"} or parsed.query or parsed.fragment:
            return ""
        try:
            port = parsed.port
        except ValueError:
            return ""
        if port is not None and not 1 <= port <= 65535:
            return ""
        return parsed.geturl()
    if probe_type == "redis":
        parsed = urlparse(target if target.lower().startswith(("redis://", "rediss://")) else "redis://" + target)
        if parsed.scheme not in {"redis", "rediss"} or not parsed.hostname or parsed.username or parsed.password or parsed.query or parsed.fragment:
            return ""
        try:
            port = parsed.port
            database = parsed.path.strip("/")
            if database and not 0 <= int(database) <= 15:
                return ""
        except ValueError:
            return ""
        if port is not None and not 1 <= port <= 65535:
            return ""
        return parsed.geturl()
    if probe_type == "smtp":
        parsed = urlparse(target if target.lower().startswith(("smtp://", "smtps://")) else "smtp://" + target)
        if parsed.scheme not in {"smtp", "smtps"} or not parsed.hostname or parsed.username or parsed.password or parsed.path not in {"", "/"} or parsed.query or parsed.fragment:
            return ""
        try:
            port = parsed.port
        except ValueError:
            return ""
        if port is not None and not 1 <= port <= 65535:
            return ""
        return parsed.geturl()
    if probe_type == "rabbitmq":
        parsed = urlparse(target if target.lower().startswith(("amqp://", "amqps://")) else "amqp://" + target)
        if parsed.scheme not in {"amqp", "amqps"} or not parsed.hostname or parsed.username or parsed.password or parsed.query or parsed.fragment:
            return ""
        try:
            port = parsed.port
        except ValueError:
            return ""
        if port is not None and not 1 <= port <= 65535:
            return ""
        return parsed.geturl()
    if probe_type == "snmp":
        parsed = urlparse(target if target.lower().startswith("snmp://") else "snmp://" + target)
        if parsed.scheme != "snmp" or not parsed.hostname or parsed.username or parsed.password or parsed.path not in {"", "/"} or parsed.query or parsed.fragment:
            return ""
        try:
            port = parsed.port
        except ValueError:
            return ""
        if port is not None and not 1 <= port <= 65535:
            return ""
        return parsed.geturl()
    if probe_type == "service":
        parsed = urlparse(target if target.lower().startswith("agent://") else "agent://" + target)
        unit = parsed.path.strip("/")
        if parsed.scheme != "agent" or not re.fullmatch(r"[a-z0-9][a-z0-9._-]{2,63}", parsed.hostname or "") or not re.fullmatch(r"(?:systemd|pm2)/[A-Za-z0-9@_.:-]{1,160}", unit) or parsed.username or parsed.password or parsed.query or parsed.fragment:
            return ""
        return parsed.geturl()
    url = target if target.lower().startswith(("http://", "https://")) else "https://" + target
    parsed = urlparse(url)
    if not parsed.netloc or not parsed.hostname or parsed.username or parsed.password or parsed.fragment:
        return ""
    return url


def _normalize_settings(settings: Dict[str, Any] | None, probe: str) -> Dict[str, Any]:
    source = settings or {}
    keyword_mode = str(source.get("keyword_mode") or "none").strip().lower()
    if keyword_mode not in {"none", "contains", "absent"}:
        keyword_mode = "none"
    dns_record = str(source.get("dns_record_type") or "A").strip().upper()
    if dns_record not in SUPPORTED_DNS_RECORDS:
        dns_record = "A"
    slo = float(source.get("slo_target_percent") or 99.9)
    slo = max(0.0, min(100.0, slo))
    body = str(source.get("request_body") or "")[:1_000_000] or None
    browser_script = str(source.get("browser_script") or "").strip()
    if browser_script:
        try:
            scenario = json.loads(browser_script)
        except json.JSONDecodeError as exc:
            raise ValueError("invalid_browser_scenario") from exc
        if not isinstance(scenario, list) or len(scenario) > 50 or any(not isinstance(step, dict) for step in scenario):
            raise ValueError("invalid_browser_scenario")
        browser_script = json.dumps(scenario, ensure_ascii=False, separators=(",", ":"))
    return {
        "name": str(source.get("name") or "").strip()[:160] or None,
        "active": 1 if _truthy(source.get("active"), True) else 0,
        "timeout_sec": _bounded_int(source.get("timeout_sec"), 10, 1, 120),
        "retry_count": _bounded_int(source.get("retry_count"), 2, 0, 10),
        "failure_threshold": _bounded_int(source.get("failure_threshold"), 2, 1, 20),
        "recovery_threshold": _bounded_int(source.get("recovery_threshold"), 2, 1, 20),
        "accepted_status_codes": _normalize_status_codes(source.get("accepted_status_codes")),
        "keyword_text": str(source.get("keyword_text") or "")[:20_000] or None,
        "keyword_mode": keyword_mode,
        "json_path": str(source.get("json_path") or "").strip()[:500] or None,
        "json_expected_value": str(source.get("json_expected_value") or "")[:20_000] or None,
        "request_headers_json": _normalize_headers(source.get("request_headers_json")),
        "request_body": body,
        "basic_auth_username": str(source.get("basic_auth_username") or "").strip()[:255] or None,
        "basic_auth_password_ciphertext": str(source.get("basic_auth_password_ciphertext") or "").strip() or None,
        "probe_config_ciphertext": str(source.get("probe_config_ciphertext") or "").strip() or None,
        "browser_script": browser_script or None,
        "diagnostics_enabled": 1 if _truthy(source.get("diagnostics_enabled"), True) else 0,
        "diagnostic_capture_body": 1 if _truthy(source.get("diagnostic_capture_body"), False) else 0,
        "tls_verify": 1 if _truthy(source.get("tls_verify"), True) else 0,
        "tls_expiry_threshold_days": _bounded_int(source.get("tls_expiry_threshold_days"), 14, 1, 365),
        "dns_record_type": dns_record,
        "dns_expected_value": str(source.get("dns_expected_value") or "").strip()[:500] or None,
        "heartbeat_grace_sec": _bounded_int(source.get("heartbeat_grace_sec"), 300, 10, 2_592_000),
        "slo_target_percent": slo,
        "public_visible": 1 if _truthy(source.get("public_visible"), True) else 0,
    }


SITE_SELECT = """
    id, url, name, created_at, probe_type, active, probe_interval_sec, timeout_sec,
    retry_count, failure_threshold, recovery_threshold, calc_method, http_methods,
    http_redirect_modes, http_primary_method, http_primary_redirect, accepted_status_codes,
    keyword_text, keyword_mode, json_path, json_expected_value, request_headers_json,
    request_body, basic_auth_username, basic_auth_password_ciphertext, probe_config_ciphertext,
    browser_script, diagnostics_enabled, diagnostic_capture_body, tls_verify, tls_expiry_threshold_days,
    dns_record_type, dns_expected_value, heartbeat_grace_sec, slo_target_percent, public_visible
"""


def list_sites(db: Database) -> dict:
    _ensure_sites_runtime_columns(db)
    rows = db.query_all(f"SELECT {SITE_SELECT} FROM sites ORDER BY created_at DESC")
    for row in rows:
        row["active"] = bool(row.get("active"))
        row["diagnostics_enabled"] = bool(row.get("diagnostics_enabled"))
        row["diagnostic_capture_body"] = bool(row.get("diagnostic_capture_body"))
        row["tls_verify"] = bool(row.get("tls_verify"))
        row["public_visible"] = bool(row.get("public_visible"))
        row["has_basic_auth_password"] = bool(row.pop("basic_auth_password_ciphertext", None))
        row["has_probe_config"] = bool(row.pop("probe_config_ciphertext", None))
    return {"ok": True, "sites": rows}


def add_site(db: Database, site_url: str, probe_type: str, interval_sec: str = "60", calc_method: str = "inherit", http_methods: str = "", http_redirect_modes: str = "", http_primary_method: str = "", http_primary_redirect: str = "", settings: Dict[str, Any] | None = None) -> dict:
    _ensure_sites_runtime_columns(db)
    probe = _normalize_probe_type(probe_type)
    if not probe:
        return {"ok": False, "status_code": 422, "error_code": "invalid_type", "message": "Invalid probe type."}
    url = _normalize_url(site_url, probe)
    if not url:
        return {"ok": False, "status_code": 422, "error_code": "invalid_target", "message": "Invalid target."}
    interval = _normalize_interval_sec(interval_sec)
    if interval <= 0:
        return {"ok": False, "status_code": 422, "error_code": "invalid_interval", "message": "Invalid interval."}
    try:
        options = _normalize_settings(settings, probe)
    except (ValueError, TypeError):
        return {"ok": False, "status_code": 422, "error_code": "invalid_headers", "message": "Invalid request headers."}
    if db.query_one("SELECT id FROM sites WHERE LOWER(url) = LOWER(%s) AND probe_type = %s LIMIT 1", (url, probe)):
        return {"ok": False, "status_code": 409, "error_code": "duplicate", "message": "Target already exists."}
    heartbeat_token = secrets.token_urlsafe(32) if probe == "heartbeat" else ""
    token_hash = __import__("hashlib").sha256(heartbeat_token.encode()).hexdigest() if heartbeat_token else None
    values = (
        url, options["name"], probe, options["active"], interval, options["timeout_sec"], options["retry_count"], options["failure_threshold"], options["recovery_threshold"],
        _normalize_calc_method(calc_method), _normalize_http_methods(http_methods or http_primary_method), _normalize_http_redirect_modes(http_redirect_modes or http_primary_redirect),
        _normalize_http_primary_method(http_primary_method), _normalize_http_primary_redirect(http_primary_redirect), options["accepted_status_codes"], options["keyword_text"],
        options["keyword_mode"], options["json_path"], options["json_expected_value"], options["request_headers_json"], options["request_body"], options["basic_auth_username"],
        options["basic_auth_password_ciphertext"], options["probe_config_ciphertext"], options["browser_script"], options["diagnostics_enabled"], options["diagnostic_capture_body"],
        options["tls_verify"], options["tls_expiry_threshold_days"], options["dns_record_type"], options["dns_expected_value"], token_hash,
        options["heartbeat_grace_sec"], options["slo_target_percent"], options["public_visible"],
    )
    site_id = db.insert(
        """INSERT INTO sites (url, name, probe_type, active, probe_interval_sec, timeout_sec, retry_count, failure_threshold, recovery_threshold, calc_method, http_methods, http_redirect_modes, http_primary_method, http_primary_redirect, accepted_status_codes, keyword_text, keyword_mode, json_path, json_expected_value, request_headers_json, request_body, basic_auth_username, basic_auth_password_ciphertext, probe_config_ciphertext, browser_script, diagnostics_enabled, diagnostic_capture_body, tls_verify, tls_expiry_threshold_days, dns_record_type, dns_expected_value, heartbeat_token_hash, heartbeat_grace_sec, slo_target_percent, public_visible) VALUES (""" + ",".join(["%s"] * 35) + ")",
        values,
    )
    probe_data = {"id": site_id, "url": url, "name": options["name"], "probe_type": probe, "probe_interval_sec": interval, "active": bool(options["active"]), "status": "unknown", "response_time": None, "http_code": None, "checked_at": None}
    if heartbeat_token:
        probe_data["heartbeat_token"] = heartbeat_token
    return {"ok": True, "status_code": 201, "probe": probe_data}


def delete_site(db: Database, site_id: int) -> dict:
    if site_id <= 0:
        return {"ok": False, "status_code": 422, "error_code": "not_found", "message": "Invalid ID."}
    if not db.query_one("SELECT id FROM sites WHERE id = %s LIMIT 1", (site_id,)):
        return {"ok": False, "status_code": 404, "error_code": "not_found", "message": "ID not found."}
    db.execute("DELETE FROM sites WHERE id = %s", (site_id,))
    return {"ok": True, "status_code": 200, "deleted_id": site_id}


def update_site(db: Database, site_id: int, site_url: str, probe_type: str, interval_sec: str = "60", calc_method: str = "inherit", http_methods: str = "", http_redirect_modes: str = "", http_primary_method: str = "", http_primary_redirect: str = "", settings: Dict[str, Any] | None = None) -> dict:
    _ensure_sites_runtime_columns(db)
    if site_id <= 0:
        return {"ok": False, "status_code": 422, "error_code": "not_found", "message": "Invalid ID."}
    existing = db.query_one("SELECT * FROM sites WHERE id = %s LIMIT 1", (site_id,))
    if not existing:
        return {"ok": False, "status_code": 404, "error_code": "not_found", "message": "ID not found."}
    probe = _normalize_probe_type(probe_type)
    url = _normalize_url(site_url, probe)
    interval = _normalize_interval_sec(interval_sec)
    if not probe:
        return {"ok": False, "status_code": 422, "error_code": "invalid_type", "message": "Invalid probe type."}
    if not url:
        return {"ok": False, "status_code": 422, "error_code": "invalid_target", "message": "Invalid target."}
    if interval <= 0:
        return {"ok": False, "status_code": 422, "error_code": "invalid_interval", "message": "Invalid interval."}
    if db.query_one("SELECT id FROM sites WHERE LOWER(url) = LOWER(%s) AND probe_type = %s AND id <> %s LIMIT 1", (url, probe, site_id)):
        return {"ok": False, "status_code": 409, "error_code": "duplicate", "message": "Target already exists."}
    merged = dict(existing)
    merged.update(settings or {})
    same_probe_type = str(existing.get("probe_type") or "").strip().lower() == probe
    if settings is not None and same_probe_type and not str(settings.get("basic_auth_password_ciphertext") or "").strip():
        merged["basic_auth_password_ciphertext"] = existing.get("basic_auth_password_ciphertext")
    if settings is not None and same_probe_type and not str(settings.get("probe_config_ciphertext") or "").strip():
        merged["probe_config_ciphertext"] = existing.get("probe_config_ciphertext")
    if not same_probe_type:
        merged["basic_auth_password_ciphertext"] = None
        merged["probe_config_ciphertext"] = None
    try:
        options = _normalize_settings(merged, probe)
    except (ValueError, TypeError):
        return {"ok": False, "status_code": 422, "error_code": "invalid_headers", "message": "Invalid request headers."}
    heartbeat_token = ""
    heartbeat_token_hash = existing.get("heartbeat_token_hash")
    if probe == "heartbeat" and not heartbeat_token_hash:
        heartbeat_token = secrets.token_urlsafe(32)
        heartbeat_token_hash = __import__("hashlib").sha256(heartbeat_token.encode()).hexdigest()
    if probe != "heartbeat":
        heartbeat_token_hash = None
    values = (
        url, options["name"], probe, options["active"], interval, options["timeout_sec"], options["retry_count"], options["failure_threshold"], options["recovery_threshold"],
        _normalize_calc_method(calc_method), _normalize_http_methods(http_methods or str(existing.get("http_methods") or "GET")),
        _normalize_http_redirect_modes(http_redirect_modes or str(existing.get("http_redirect_modes") or "follow")),
        _normalize_http_primary_method(http_primary_method or str(existing.get("http_primary_method") or "GET")),
        _normalize_http_primary_redirect(http_primary_redirect or str(existing.get("http_primary_redirect") or "follow")), options["accepted_status_codes"], options["keyword_text"],
        options["keyword_mode"], options["json_path"], options["json_expected_value"], options["request_headers_json"], options["request_body"], options["basic_auth_username"],
        options["basic_auth_password_ciphertext"], options["probe_config_ciphertext"], options["browser_script"], options["diagnostics_enabled"], options["diagnostic_capture_body"],
        options["tls_verify"], options["tls_expiry_threshold_days"], options["dns_record_type"], options["dns_expected_value"],
        heartbeat_token_hash, options["heartbeat_grace_sec"], options["slo_target_percent"], options["public_visible"], site_id,
    )
    db.execute(
        """UPDATE sites SET url=%s, name=%s, probe_type=%s, active=%s, probe_interval_sec=%s, timeout_sec=%s, retry_count=%s, failure_threshold=%s, recovery_threshold=%s, calc_method=%s, http_methods=%s, http_redirect_modes=%s, http_primary_method=%s, http_primary_redirect=%s, accepted_status_codes=%s, keyword_text=%s, keyword_mode=%s, json_path=%s, json_expected_value=%s, request_headers_json=%s, request_body=%s, basic_auth_username=%s, basic_auth_password_ciphertext=%s, probe_config_ciphertext=%s, browser_script=%s, diagnostics_enabled=%s, diagnostic_capture_body=%s, tls_verify=%s, tls_expiry_threshold_days=%s, dns_record_type=%s, dns_expected_value=%s, heartbeat_token_hash=%s, heartbeat_grace_sec=%s, slo_target_percent=%s, public_visible=%s WHERE id=%s""",
        values,
    )
    probe_data = {"id": site_id, "url": url, "name": options["name"], "probe_type": probe, "probe_interval_sec": interval, "active": bool(options["active"]), "status": "paused" if not options["active"] else "unknown", "response_time": None, "http_code": None, "checked_at": None}
    if heartbeat_token:
        probe_data["heartbeat_token"] = heartbeat_token
    return {"ok": True, "status_code": 200, "probe": probe_data}
