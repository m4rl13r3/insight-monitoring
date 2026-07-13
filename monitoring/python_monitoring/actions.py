from __future__ import annotations

import ipaddress
import re
from urllib.parse import urlparse

from .db import Database


SUPPORTED_INTERVALS_SEC = {60, 120, 180, 300, 600, 1800, 21600, 43200, 86400}
SUPPORTED_CALC_METHODS = {"inherit", "legacy", "time_weighted"}


def _normalize_probe_type(value: str) -> str:
    probe = (value or "").strip().lower()
    return probe if probe in {"http", "ping", "icmp", "tcp", "dns", "grpc"} else "http"


def _normalize_interval_sec(value: str) -> int:
    try:
        interval = int(str(value).strip())
    except Exception:
        interval = 60
    return interval if interval in SUPPORTED_INTERVALS_SEC else 60


def _normalize_calc_method(value: str) -> str:
    method = (value or "").strip().lower()
    return method if method in SUPPORTED_CALC_METHODS else "inherit"


def _normalize_http_methods(value: str) -> str:
    raw = (value or "").strip()
    return raw if raw != "" else "GET,POST,PUT,HEAD,DELETE,PATCH,OPTIONS"


def _normalize_http_redirect_modes(value: str) -> str:
    raw = (value or "").strip()
    return raw if raw != "" else "follow,no_follow"


def _normalize_http_primary_method(value: str) -> str:
    raw = (value or "").strip().upper()
    return raw if raw != "" else "GET"


def _normalize_http_primary_redirect(value: str) -> str:
    raw = (value or "").strip().lower()
    return raw if raw != "" else "follow"


def _ensure_sites_runtime_columns(db: Database) -> None:
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
        except Exception:
            pass


def _normalize_url(value: str, probe_type: str = "http") -> str:
    target = (value or "").strip()
    if not target:
        return ""
    if probe_type in {"icmp", "ping"}:
        candidate = target[1:-1] if target.startswith("[") and target.endswith("]") else target
        if any(marker in candidate for marker in ("://", "/", "?", "#", "@")) or any(char.isspace() for char in candidate):
            return ""
        try:
            return ipaddress.ip_address(candidate).compressed.lower()
        except ValueError:
            pass
        if ":" in candidate or not re.fullmatch(
            r"(?=.{1,253}\.?$)(?!-)(?:[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?\.)*(?:[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?)\.?",
            candidate,
            re.IGNORECASE,
        ):
            return ""
        return candidate.lower()
    if probe_type in {"tcp", "dns", "grpc"}:
        parsed = urlparse(target if "://" in target else "//" + target)
        return target if parsed.hostname else ""
    url = target
    if not url.lower().startswith(("http://", "https://")):
        url = "https://" + url
    parsed = urlparse(url)
    if not parsed.netloc:
        return ""
    return url


def list_sites(db: Database) -> dict:
    _ensure_sites_runtime_columns(db)
    rows = db.query_all(
        """
        SELECT
            id,
            url,
            created_at,
            probe_type,
            probe_interval_sec,
            calc_method,
            http_methods,
            http_redirect_modes,
            http_primary_method,
            http_primary_redirect
        FROM sites
        ORDER BY created_at DESC
        """
    )
    normalized_rows = []
    for row in rows:
        row_copy = dict(row)
        row_copy["probe_interval_sec"] = _normalize_interval_sec(str(row.get("probe_interval_sec", "60")))
        normalized_rows.append(row_copy)
    return {"ok": True, "sites": normalized_rows}


def add_site(
    db: Database,
    site_url: str,
    probe_type: str,
    interval_sec: str = "60",
    calc_method: str = "inherit",
    http_methods: str = "",
    http_redirect_modes: str = "",
    http_primary_method: str = "",
    http_primary_redirect: str = "",
) -> dict:
    _ensure_sites_runtime_columns(db)
    probe = _normalize_probe_type(probe_type)
    url = _normalize_url(site_url, probe)
    if not url:
        return {"ok": False, "status_code": 400, "message": "Invalid URL."}
    normalized_interval = _normalize_interval_sec(interval_sec)
    normalized_calc_method = _normalize_calc_method(calc_method)
    normalized_http_methods = _normalize_http_methods(http_methods)
    normalized_http_redirect_modes = _normalize_http_redirect_modes(http_redirect_modes)
    normalized_http_primary_method = _normalize_http_primary_method(http_primary_method)
    normalized_http_primary_redirect = _normalize_http_primary_redirect(http_primary_redirect)
    db.execute(
        """
        INSERT INTO sites
            (url, probe_type, probe_interval_sec, calc_method, http_methods, http_redirect_modes, http_primary_method, http_primary_redirect)
        VALUES (%s, %s, %s, %s, %s, %s, %s, %s)
        """,
        (
            url,
            probe,
            normalized_interval,
            normalized_calc_method,
            normalized_http_methods,
            normalized_http_redirect_modes,
            normalized_http_primary_method,
            normalized_http_primary_redirect,
        ),
    )
    return {"ok": True}


def delete_site(db: Database, site_id: int) -> dict:
    if site_id <= 0:
        return {"ok": False, "status_code": 400, "message": "Invalid ID."}

    row = db.query_one("SELECT id FROM sites WHERE id = %s LIMIT 1", (site_id,))
    if not row:
        return {"ok": False, "status_code": 404, "message": "ID not found."}

    db.execute("DELETE FROM sites WHERE id = %s", (site_id,))
    return {"ok": True}


def update_site(
    db: Database,
    site_id: int,
    site_url: str,
    probe_type: str,
    interval_sec: str = "60",
    calc_method: str = "inherit",
    http_methods: str = "",
    http_redirect_modes: str = "",
    http_primary_method: str = "",
    http_primary_redirect: str = "",
) -> dict:
    _ensure_sites_runtime_columns(db)
    if site_id <= 0:
        return {"ok": False, "status_code": 400, "message": "Invalid ID."}

    probe = _normalize_probe_type(probe_type)
    url = _normalize_url(site_url, probe)
    if not url:
        return {"ok": False, "status_code": 400, "message": "Invalid URL."}
    normalized_interval = _normalize_interval_sec(interval_sec)
    normalized_calc_method = _normalize_calc_method(calc_method)
    normalized_http_methods = _normalize_http_methods(http_methods)
    normalized_http_redirect_modes = _normalize_http_redirect_modes(http_redirect_modes)
    normalized_http_primary_method = _normalize_http_primary_method(http_primary_method)
    normalized_http_primary_redirect = _normalize_http_primary_redirect(http_primary_redirect)

    row = db.query_one("SELECT id FROM sites WHERE id = %s LIMIT 1", (site_id,))
    if not row:
        return {"ok": False, "status_code": 404, "message": "ID not found."}

    db.execute(
        """
        UPDATE sites
        SET
            url = %s,
            probe_type = %s,
            probe_interval_sec = %s,
            calc_method = %s,
            http_methods = %s,
            http_redirect_modes = %s,
            http_primary_method = %s,
            http_primary_redirect = %s
        WHERE id = %s
        """,
        (
            url,
            probe,
            normalized_interval,
            normalized_calc_method,
            normalized_http_methods,
            normalized_http_redirect_modes,
            normalized_http_primary_method,
            normalized_http_primary_redirect,
            site_id,
        ),
    )
    return {"ok": True}
