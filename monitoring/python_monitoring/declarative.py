from __future__ import annotations

import json
import re
from decimal import Decimal
from pathlib import Path
from typing import Any

import yaml

from .actions import SUPPORTED_CALC_METHODS, SUPPORTED_INTERVALS_SEC, SUPPORTED_PROBE_TYPES
from .db import Database


MONITOR_FIELDS = (
    "name",
    "active",
    "probe_interval_sec",
    "timeout_sec",
    "retry_count",
    "failure_threshold",
    "recovery_threshold",
    "calc_method",
    "http_methods",
    "http_redirect_modes",
    "http_primary_method",
    "http_primary_redirect",
    "accepted_status_codes",
    "keyword_text",
    "keyword_mode",
    "json_path",
    "json_expected_value",
    "diagnostics_enabled",
    "diagnostic_capture_body",
    "tls_verify",
    "tls_expiry_threshold_days",
    "dns_record_type",
    "dns_expected_value",
    "heartbeat_grace_sec",
    "slo_target_percent",
    "public_visible",
    "probe_replication_factor",
    "probe_success_quorum",
    "probe_failure_quorum",
)


def _boolean(value: Any, default: bool = False) -> bool:
    if isinstance(value, bool):
        return value
    if value is None:
        return default
    return str(value).strip().lower() in {"1", "true", "yes", "on"}


def _integer(value: Any, default: int, minimum: int, maximum: int) -> int:
    try:
        parsed = int(value)
    except Exception:
        parsed = default
    return max(minimum, min(maximum, parsed))


def _number(value: Any, default: float, minimum: float, maximum: float) -> float:
    try:
        parsed = float(value)
    except Exception:
        parsed = default
    return max(minimum, min(maximum, parsed))


def _monitor_key(probe_type: str, target: str) -> str:
    return f"{probe_type}:{target}"


def _plain(value: Any) -> Any:
    if isinstance(value, Decimal):
        return float(value)
    return value


def _normalize_monitor(item: Any) -> dict[str, Any]:
    if not isinstance(item, dict):
        raise ValueError("Each monitor must be an object.")
    target = str(item.get("target") or item.get("url") or "").strip()
    probe_type = str(item.get("type") or item.get("probe_type") or "http").strip().lower()
    if not target or len(target) > 255:
        raise ValueError("Each monitor requires a target of at most 255 characters.")
    if probe_type not in SUPPORTED_PROBE_TYPES:
        raise ValueError(f"Unsupported monitor type: {probe_type}.")
    interval = _integer(item.get("interval_seconds", item.get("probe_interval_sec", 60)), 60, 10, 86400)
    if interval not in SUPPORTED_INTERVALS_SEC:
        raise ValueError(f"Unsupported interval for {_monitor_key(probe_type, target)}.")
    method = str(item.get("calculation", item.get("calc_method", "inherit"))).strip().lower()
    if method not in SUPPORTED_CALC_METHODS:
        raise ValueError(f"Unsupported calculation method for {_monitor_key(probe_type, target)}.")
    normalized = {
        "key": _monitor_key(probe_type, target),
        "target": target,
        "type": probe_type,
        "name": str(item.get("name") or "").strip()[:160] or None,
        "active": _boolean(item.get("active"), True),
        "probe_interval_sec": interval,
        "timeout_sec": _integer(item.get("timeout_seconds", item.get("timeout_sec", 10)), 10, 1, 300),
        "retry_count": _integer(item.get("retries", item.get("retry_count", 2)), 2, 0, 10),
        "failure_threshold": _integer(item.get("failure_threshold", 2), 2, 1, 50),
        "recovery_threshold": _integer(item.get("recovery_threshold", 2), 2, 1, 50),
        "calc_method": method,
        "http_methods": str(item.get("http_methods") or "GET").strip().upper()[:128] or "GET",
        "http_redirect_modes": str(item.get("http_redirect_modes") or "follow").strip().lower()[:32] or "follow",
        "http_primary_method": str(item.get("http_primary_method") or "GET").strip().upper()[:16] or "GET",
        "http_primary_redirect": str(item.get("http_primary_redirect") or "follow").strip().lower()[:16] or "follow",
        "accepted_status_codes": str(item.get("accepted_status_codes") or "200-399").strip()[:255] or "200-399",
        "keyword_text": str(item.get("keyword_text") or "")[:20000] or None,
        "keyword_mode": str(item.get("keyword_mode") or "none").strip().lower(),
        "json_path": str(item.get("json_path") or "").strip()[:500] or None,
        "json_expected_value": str(item.get("json_expected_value") or "")[:20000] or None,
        "diagnostics_enabled": _boolean(item.get("diagnostics_enabled"), True),
        "diagnostic_capture_body": _boolean(item.get("diagnostic_capture_body"), False),
        "tls_verify": _boolean(item.get("tls_verify"), True),
        "tls_expiry_threshold_days": _integer(item.get("tls_expiry_threshold_days", 14), 14, 1, 365),
        "dns_record_type": str(item.get("dns_record_type") or "A").strip().upper()[:12] or "A",
        "dns_expected_value": str(item.get("dns_expected_value") or "").strip()[:500] or None,
        "heartbeat_grace_sec": _integer(item.get("heartbeat_grace_seconds", item.get("heartbeat_grace_sec", 300)), 300, 10, 86400),
        "slo_target_percent": _number(item.get("slo_target_percent", 99.9), 99.9, 0.0, 100.0),
        "public_visible": _boolean(item.get("public_visible"), True),
        "probe_replication_factor": _integer(item.get("replication_factor", item.get("probe_replication_factor", 0)), 0, 0, 100),
        "probe_success_quorum": _integer(item.get("success_quorum", item.get("probe_success_quorum", 0)), 0, 0, 100),
        "probe_failure_quorum": _integer(item.get("failure_quorum", item.get("probe_failure_quorum", 0)), 0, 0, 100),
    }
    if normalized["keyword_mode"] not in {"none", "contains", "absent"}:
        raise ValueError(f"Invalid keyword mode for {normalized['key']}.")
    return normalized


def _normalize_runbook(item: Any) -> dict[str, Any]:
    if not isinstance(item, dict):
        raise ValueError("Each runbook must be an object.")
    slug = str(item.get("slug") or "").strip().lower()
    name = str(item.get("name") or "").strip()
    content = str(item.get("content") or "").replace("\r\n", "\n").replace("\r", "\n")
    if re.fullmatch(r"[a-z0-9]+(?:-[a-z0-9]+)*", slug) is None or not name or len(name) > 160 or len(content) > 100000:
        raise ValueError("A runbook has an invalid slug, name, or content.")
    return {"slug": slug, "name": name, "content": content, "enabled": _boolean(item.get("enabled"), True)}


def _normalize_page(item: Any, monitor_keys: set[str]) -> dict[str, Any]:
    if not isinstance(item, dict):
        raise ValueError("Each status page must be an object.")
    slug = str(item.get("slug") or "").strip().lower()
    name = str(item.get("name") or "").strip()
    if re.fullmatch(r"[a-z0-9](?:[a-z0-9-]{0,118}[a-z0-9])?", slug) is None or not name or len(name) > 160:
        raise ValueError("A status page has an invalid slug or name.")
    access = str(item.get("access") or item.get("access_policy") or "public").strip().lower()
    if access not in {"public", "password", "sso", "ip_allowlist"}:
        raise ValueError(f"Unsupported access policy for status page {slug}.")
    monitor_refs = [str(value).strip() for value in item.get("monitors", [])]
    groups = []
    seen: set[str] = set()
    for reference in monitor_refs:
        if reference not in monitor_keys or reference in seen:
            raise ValueError(f"Unknown or duplicate monitor reference on status page {slug}: {reference}.")
        seen.add(reference)
    for position, group in enumerate(item.get("groups", [])):
        if not isinstance(group, dict) or not str(group.get("name") or "").strip():
            raise ValueError(f"Invalid monitor group on status page {slug}.")
        references = [str(value).strip() for value in group.get("monitors", [])]
        for reference in references:
            if reference not in monitor_keys or reference in seen:
                raise ValueError(f"Unknown or duplicate monitor reference on status page {slug}: {reference}.")
            seen.add(reference)
        groups.append({"name": str(group["name"]).strip()[:160], "collapsed": _boolean(group.get("collapsed"), False), "position": position, "monitors": references})
    navigation = []
    for link in item.get("navigation", []):
        if not isinstance(link, dict) or not str(link.get("label") or "").strip() or not str(link.get("url") or "").strip():
            raise ValueError(f"Invalid navigation link on status page {slug}.")
        navigation.append({"label": str(link["label"]).strip()[:80], "url": str(link["url"]).strip()[:1000]})
    if len(navigation) > 8:
        raise ValueError(f"Too many navigation links on status page {slug}.")
    ip_allowlist = str(item.get("ip_allowlist") or "").strip()
    if access == "ip_allowlist" and not ip_allowlist:
        raise ValueError(f"Status page {slug} requires an IP allowlist.")
    return {
        "slug": slug,
        "name": name,
        "description": str(item.get("description") or "")[:20000],
        "custom_domain": str(item.get("custom_domain") or "").strip()[:255] or None,
        "access_policy": access,
        "ip_allowlist": ip_allowlist or None,
        "theme": str(item.get("theme") or "system").strip().lower() if str(item.get("theme") or "system").strip().lower() in {"system", "light", "dark"} else "system",
        "accent_color": str(item.get("accent_color") or "#16a34a").strip().lower(),
        "logo_url": str(item.get("logo_url") or "").strip()[:1000] or None,
        "favicon_url": str(item.get("favicon_url") or "").strip()[:1000] or None,
        "announcement": str(item.get("announcement") or "").strip()[:1000] or None,
        "announcement_url": str(item.get("announcement_url") or "").strip()[:1000] or None,
        "navigation_links_json": json.dumps(navigation, ensure_ascii=False, separators=(",", ":")),
        "custom_css": str(item.get("custom_css") or "")[:20000] or None,
        "history_days": _integer(item.get("history_days", 90), 90, 1, 365),
        "hide_from_search_engines": _boolean(item.get("hide_from_search_engines"), access != "public"),
        "locale": str(item.get("locale") or "auto").strip().lower()[:8] or "auto",
        "enabled": _boolean(item.get("enabled"), True),
        "monitors": monitor_refs,
        "groups": groups,
    }


def normalize_configuration(document: Any) -> dict[str, Any]:
    if not isinstance(document, dict) or int(document.get("version") or 0) != 1:
        raise ValueError("Configuration version 1 is required.")
    monitors = [_normalize_monitor(item) for item in document.get("monitors", [])]
    keys = [item["key"] for item in monitors]
    if len(keys) != len(set(keys)):
        raise ValueError("Monitor targets and types must be unique.")
    runbooks = [_normalize_runbook(item) for item in document.get("runbooks", [])]
    if len({item["slug"] for item in runbooks}) != len(runbooks):
        raise ValueError("Runbook slugs must be unique.")
    pages = [_normalize_page(item, set(keys)) for item in document.get("status_pages", [])]
    if len({item["slug"] for item in pages}) != len(pages):
        raise ValueError("Status page slugs must be unique.")
    return {"version": 1, "monitors": monitors, "runbooks": runbooks, "status_pages": pages}


def load_configuration(path: str) -> dict[str, Any]:
    raw = Path(path).read_text(encoding="utf-8") if path != "-" else __import__("sys").stdin.read()
    try:
        document = json.loads(raw) if path.lower().endswith(".json") else yaml.safe_load(raw)
    except Exception as exc:
        raise ValueError(f"Unable to parse configuration: {exc}") from exc
    return normalize_configuration(document)


def export_configuration(db: Database) -> dict[str, Any]:
    monitor_rows = db.query_all("SELECT * FROM sites ORDER BY id")
    monitors = []
    monitor_keys: dict[int, str] = {}
    for row in monitor_rows:
        key = _monitor_key(str(row.get("probe_type") or "http"), str(row.get("url") or ""))
        monitor_keys[int(row.get("id") or 0)] = key
        monitor = {"target": row.get("url"), "type": row.get("probe_type"), "name": row.get("name"), "active": bool(row.get("active")), "interval_seconds": int(row.get("probe_interval_sec") or 60), "calculation": row.get("calc_method") or "inherit"}
        for field in MONITOR_FIELDS:
            if field in {"name", "active", "probe_interval_sec", "calc_method"}:
                continue
            value = row.get(field)
            if value is not None and value != "":
                monitor[field] = bool(value) if field in {"diagnostics_enabled", "diagnostic_capture_body", "tls_verify", "public_visible"} else _plain(value)
        if any(row.get(field) for field in ("request_headers_json", "request_body", "basic_auth_password_ciphertext", "probe_config_ciphertext", "browser_script", "heartbeat_token_hash")):
            monitor["protected_configuration_preserved"] = True
        monitors.append(monitor)
    runbooks = [{"slug": row["slug"], "name": row["name"], "content": row.get("content") or "", "enabled": bool(row.get("enabled"))} for row in db.query_all("SELECT slug,name,content,enabled FROM runbooks ORDER BY slug")]
    pages = []
    for page in db.query_all("SELECT * FROM status_pages ORDER BY id"):
        page_id = int(page.get("id") or 0)
        groups = db.query_all("SELECT id,name,sort_order,collapsed FROM status_page_groups WHERE status_page_id=%s ORDER BY sort_order,id", (page_id,))
        memberships = db.query_all("SELECT site_id,group_id,sort_order FROM status_page_monitors WHERE status_page_id=%s AND visible=1 ORDER BY sort_order,site_id", (page_id,))
        grouped: dict[int, list[str]] = {}
        ungrouped: list[str] = []
        for membership in memberships:
            reference = monitor_keys.get(int(membership.get("site_id") or 0))
            if not reference:
                continue
            group_id = int(membership.get("group_id") or 0)
            grouped.setdefault(group_id, []).append(reference) if group_id else ungrouped.append(reference)
        navigation = json.loads(str(page.get("navigation_links_json") or "[]"))
        pages.append({
            "slug": page.get("slug"),
            "name": page.get("name"),
            "description": page.get("description") or "",
            "custom_domain": page.get("custom_domain"),
            "access": page.get("access_policy") or ("password" if page.get("visibility") == "private" else "public"),
            "ip_allowlist": page.get("ip_allowlist") or "",
            "theme": page.get("theme") or "system",
            "accent_color": page.get("accent_color") or "#16a34a",
            "logo_url": page.get("logo_url"),
            "favicon_url": page.get("favicon_url"),
            "announcement": page.get("announcement"),
            "announcement_url": page.get("announcement_url"),
            "navigation": navigation if isinstance(navigation, list) else [],
            "custom_css": page.get("custom_css") or "",
            "history_days": int(page.get("history_days") or 90),
            "hide_from_search_engines": bool(page.get("hide_from_search_engines")),
            "locale": page.get("locale") or "auto",
            "enabled": bool(page.get("enabled")),
            "monitors": ungrouped,
            "groups": [{"name": group["name"], "collapsed": bool(group.get("collapsed")), "monitors": grouped.get(int(group["id"]), [])} for group in groups],
        })
    return {"version": 1, "monitors": monitors, "runbooks": runbooks, "status_pages": pages}


def render_configuration(document: dict[str, Any], output_format: str = "yaml") -> str:
    if output_format == "json":
        return json.dumps(document, ensure_ascii=False, indent=2, default=str) + "\n"
    return yaml.safe_dump(document, sort_keys=False, allow_unicode=True, default_flow_style=False)


def apply_configuration(db: Database, document: dict[str, Any], prune: bool = False, dry_run: bool = False) -> dict[str, Any]:
    normalized = normalize_configuration(document)
    existing_monitors = {str(row["key_value"]): row for row in db.query_all("SELECT id,CONCAT(probe_type,':',url) AS key_value FROM sites")}
    existing_runbooks = {str(row["slug"]): row for row in db.query_all("SELECT id,slug FROM runbooks")}
    existing_pages = {str(row["slug"]): row for row in db.query_all("SELECT id,slug,password_hash FROM status_pages")}
    summary = {
        "ok": True,
        "dry_run": dry_run,
        "monitors": {"created": 0, "updated": 0, "disabled": 0},
        "runbooks": {"created": 0, "updated": 0, "disabled": 0},
        "status_pages": {"created": 0, "updated": 0, "disabled": 0},
    }
    for monitor in normalized["monitors"]:
        summary["monitors"]["updated" if monitor["key"] in existing_monitors else "created"] += 1
    for runbook in normalized["runbooks"]:
        summary["runbooks"]["updated" if runbook["slug"] in existing_runbooks else "created"] += 1
    for page in normalized["status_pages"]:
        if page["access_policy"] == "password" and not str(existing_pages.get(page["slug"], {}).get("password_hash") or ""):
            raise ValueError(f"Status page {page['slug']} requires a password to be set once from the dashboard.")
        summary["status_pages"]["updated" if page["slug"] in existing_pages else "created"] += 1
    monitor_keys = {item["key"] for item in normalized["monitors"]}
    runbook_slugs = {item["slug"] for item in normalized["runbooks"]}
    page_slugs = {item["slug"] for item in normalized["status_pages"]}
    if prune:
        summary["monitors"]["disabled"] = len(set(existing_monitors) - monitor_keys)
        summary["runbooks"]["disabled"] = len(set(existing_runbooks) - runbook_slugs)
        summary["status_pages"]["disabled"] = len({slug for slug in existing_pages if slug != "default"} - page_slugs)
    if dry_run:
        return summary
    db.begin()
    try:
        site_ids: dict[str, int] = {}
        columns = list(MONITOR_FIELDS)
        assignments = ",".join(f"{field}=VALUES({field})" for field in columns)
        placeholders = ",".join(["%s"] * (2 + len(columns)))
        for monitor in normalized["monitors"]:
            values = [monitor["target"], monitor["type"]] + [int(monitor[field]) if isinstance(monitor[field], bool) else monitor[field] for field in columns]
            db.execute(f"INSERT INTO sites (url,probe_type,{','.join(columns)}) VALUES ({placeholders}) ON DUPLICATE KEY UPDATE {assignments}", tuple(values))
            row = db.query_one("SELECT id FROM sites WHERE url=%s AND probe_type=%s LIMIT 1", (monitor["target"], monitor["type"]))
            site_ids[monitor["key"]] = int((row or {}).get("id") or 0)
        if prune and monitor_keys:
            db.execute("UPDATE sites SET active=0 WHERE CONCAT(probe_type,':',url) NOT IN (" + ",".join(["%s"] * len(monitor_keys)) + ")", tuple(sorted(monitor_keys)))
        elif prune:
            db.execute("UPDATE sites SET active=0")
        for runbook in normalized["runbooks"]:
            db.execute("INSERT INTO runbooks (slug,name,content,enabled) VALUES (%s,%s,%s,%s) ON DUPLICATE KEY UPDATE name=VALUES(name),content=VALUES(content),enabled=VALUES(enabled)", (runbook["slug"], runbook["name"], runbook["content"], int(runbook["enabled"])))
        if prune and runbook_slugs:
            db.execute("UPDATE runbooks SET enabled=0 WHERE slug NOT IN (" + ",".join(["%s"] * len(runbook_slugs)) + ")", tuple(sorted(runbook_slugs)))
        elif prune:
            db.execute("UPDATE runbooks SET enabled=0")
        for page in normalized["status_pages"]:
            visibility = "public" if page["access_policy"] == "public" else "private"
            values = (page["slug"], page["name"], page["description"], page["custom_domain"], visibility, page["access_policy"], page["ip_allowlist"], page["theme"], page["accent_color"], page["logo_url"], page["favicon_url"], page["announcement"], page["announcement_url"], page["navigation_links_json"], page["custom_css"], page["history_days"], int(page["hide_from_search_engines"]), page["locale"], int(page["enabled"]))
            db.execute("INSERT INTO status_pages (slug,name,description,custom_domain,visibility,access_policy,ip_allowlist,theme,accent_color,logo_url,favicon_url,announcement,announcement_url,navigation_links_json,custom_css,history_days,hide_from_search_engines,locale,enabled) VALUES (" + ",".join(["%s"] * 19) + ") ON DUPLICATE KEY UPDATE name=VALUES(name),description=VALUES(description),custom_domain=VALUES(custom_domain),visibility=VALUES(visibility),access_policy=VALUES(access_policy),ip_allowlist=VALUES(ip_allowlist),theme=VALUES(theme),accent_color=VALUES(accent_color),logo_url=VALUES(logo_url),favicon_url=VALUES(favicon_url),announcement=VALUES(announcement),announcement_url=VALUES(announcement_url),navigation_links_json=VALUES(navigation_links_json),custom_css=VALUES(custom_css),history_days=VALUES(history_days),hide_from_search_engines=VALUES(hide_from_search_engines),locale=VALUES(locale),enabled=VALUES(enabled)", values)
            row = db.query_one("SELECT id FROM status_pages WHERE slug=%s LIMIT 1", (page["slug"],))
            page_id = int((row or {}).get("id") or 0)
            db.execute("DELETE FROM status_page_monitors WHERE status_page_id=%s", (page_id,))
            db.execute("DELETE FROM status_page_groups WHERE status_page_id=%s", (page_id,))
            position = 0
            for reference in page["monitors"]:
                db.execute("INSERT INTO status_page_monitors (status_page_id,site_id,group_id,sort_order) VALUES (%s,%s,NULL,%s)", (page_id, site_ids[reference], position))
                position += 1
            for group in page["groups"]:
                group_id = db.insert("INSERT INTO status_page_groups (status_page_id,name,sort_order,collapsed) VALUES (%s,%s,%s,%s)", (page_id, group["name"], group["position"], int(group["collapsed"])))
                for reference in group["monitors"]:
                    db.execute("INSERT INTO status_page_monitors (status_page_id,site_id,group_id,sort_order) VALUES (%s,%s,%s,%s)", (page_id, site_ids[reference], group_id, position))
                    position += 1
        if prune and page_slugs:
            db.execute("UPDATE status_pages SET enabled=0 WHERE slug<>'default' AND slug NOT IN (" + ",".join(["%s"] * len(page_slugs)) + ")", tuple(sorted(page_slugs)))
        elif prune:
            db.execute("UPDATE status_pages SET enabled=0 WHERE slug<>'default'")
        db.commit()
    except Exception:
        db.rollback()
        raise
    return summary
