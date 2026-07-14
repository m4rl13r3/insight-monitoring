from __future__ import annotations

import hashlib
import hmac
import json
import logging
import math
import os
import re
import time
from datetime import datetime, timedelta, timezone
from typing import Any
from urllib.parse import urlparse

from .db import Database
from .notifications import decrypt_config, dispatch_event
from .oncall import apply_oncall_escalations
from .reinforced import activate_reinforced_watch, effective_interval, watch_is_active


class DistributedError(RuntimeError):
    def __init__(self, message: str, status_code: int = 400):
        super().__init__(message)
        self.status_code = status_code


def env_int(name: str, default: int, minimum: int, maximum: int) -> int:
    try:
        value = int(str(os.getenv(name, default)).strip())
    except Exception:
        value = default
    return max(minimum, min(maximum, value))


def env_bool(name: str, default: bool = False) -> bool:
    return str(os.getenv(name, "1" if default else "0")).strip().lower() in {"1", "true", "yes", "on"}


def validate_node_key(node_key: str) -> str:
    normalized = str(node_key or "").strip().lower()
    if re.fullmatch(r"[a-z0-9][a-z0-9._-]{2,63}", normalized) is None:
        raise DistributedError("Invalid node identifier.", 400)
    return normalized


def master_secret() -> str:
    secret = str(os.getenv("INSIGHT_AGENT_MASTER_SECRET", "")).strip()
    if len(secret) < 32:
        raise DistributedError("INSIGHT_AGENT_MASTER_SECRET must contain at least 32 characters.", 503)
    return secret


def derive_node_secret(node_key: str, secret: str | None = None) -> str:
    key = validate_node_key(node_key)
    return hmac.new(
        (secret if secret is not None else master_secret()).encode("utf-8"),
        f"insight-agent-v1:{key}".encode("utf-8"),
        hashlib.sha256,
    ).hexdigest()


def signature_payload(node_key: str, timestamp: str, nonce: str, raw_body: str) -> str:
    body_hash = hashlib.sha256(raw_body.encode("utf-8")).hexdigest()
    return f"v1\n{node_key}\n{timestamp}\n{nonce}\n{body_hash}"


def verify_signature(node_key: str, timestamp: str, nonce: str, signature: str, raw_body: str) -> str:
    key = validate_node_key(node_key)
    if not str(timestamp).isdigit():
        raise DistributedError("Invalid agent timestamp.", 401)
    if re.fullmatch(r"[A-Za-z0-9._:-]{16,96}", str(nonce or "")) is None:
        raise DistributedError("Invalid agent nonce.", 401)
    if re.fullmatch(r"[a-fA-F0-9]{64}", str(signature or "")) is None:
        raise DistributedError("Invalid agent signature.", 401)
    window = env_int("INSIGHT_AGENT_HMAC_WINDOW_SEC", 300, 30, 3600)
    if abs(int(time.time()) - int(timestamp)) > window:
        raise DistributedError("Agent signature has expired.", 401)
    expected = hmac.new(
        derive_node_secret(key).encode("utf-8"),
        signature_payload(key, timestamp, nonce, raw_body).encode("utf-8"),
        hashlib.sha256,
    ).hexdigest()
    if not hmac.compare_digest(expected, signature.lower()):
        raise DistributedError("Invalid agent signature.", 401)
    return key


def _clean_text(value: Any, maximum: int) -> str | None:
    text = str(value or "").strip()
    return text[:maximum] if text else None


def _database_datetime(value: Any) -> datetime | None:
    if isinstance(value, datetime):
        return value.replace(tzinfo=None)
    raw = str(value or "").strip()
    if not raw:
        return None
    try:
        parsed = datetime.fromisoformat(raw.replace("Z", "+00:00"))
    except ValueError:
        return None
    if parsed.tzinfo is not None:
        parsed = parsed.astimezone().replace(tzinfo=None)
    return parsed


def _format_datetime(value: datetime, milliseconds: bool = False) -> str:
    return value.strftime("%Y-%m-%d %H:%M:%S.%f")[:23] if milliseconds else value.strftime("%Y-%m-%d %H:%M:%S")


def format_unix_milliseconds(timestamp: float) -> str:
    return _format_datetime(datetime.fromtimestamp(timestamp), milliseconds=True)


def register_node(db: Database, node_key: str, body: dict[str, Any], remote_address: str) -> dict[str, Any]:
    key = validate_node_key(node_key)
    existing = db.query_one("SELECT * FROM monitoring_nodes WHERE node_key = %s LIMIT 1", (key,))
    if existing is None and not env_bool("INSIGHT_AGENT_AUTO_REGISTER", True):
        raise DistributedError("Automatic node registration is disabled.", 403)
    if str((existing or {}).get("status") or "") == "revoked":
        raise DistributedError("This node has been revoked.", 403)
    node = body.get("node") if isinstance(body.get("node"), dict) else {}
    display_name = _clean_text(node.get("display_name") or key, 120) or key
    region = _clean_text(node.get("region"), 64)
    zone = _clean_text(node.get("zone"), 64)
    version = _clean_text(node.get("version"), 32)
    connectivity = str(node.get("connectivity_status") or "unknown").strip().lower()
    if connectivity not in {"online", "offline", "unknown"}:
        connectivity = "unknown"
    capabilities = node.get("capabilities") if isinstance(node.get("capabilities"), (list, dict)) else []
    capabilities_json = json.dumps(capabilities, ensure_ascii=False, separators=(",", ":"))
    if len(capabilities_json.encode("utf-8")) > 8192:
        capabilities_json = "{}"
    try:
        sent_at_ms = int(body.get("sent_at_ms"))
        clock_skew = max(-2147483648, min(2147483647, int(round(time.time() * 1000 - sent_at_ms))))
    except Exception:
        clock_skew = 0
    ip_hash = hmac.new(master_secret().encode("utf-8"), str(remote_address or "unknown").encode("utf-8"), hashlib.sha256).hexdigest()
    db.execute(
        """
        INSERT INTO monitoring_nodes
            (node_key, display_name, region, zone, version, capabilities, connectivity_status, clock_skew_ms, last_ip_hash)
        VALUES (%s, %s, %s, %s, %s, %s, %s, %s, %s)
        ON DUPLICATE KEY UPDATE
            display_name = VALUES(display_name),
            region = VALUES(region),
            zone = VALUES(zone),
            version = VALUES(version),
            capabilities = VALUES(capabilities),
            connectivity_status = VALUES(connectivity_status),
            clock_skew_ms = VALUES(clock_skew_ms),
            last_ip_hash = VALUES(last_ip_hash),
            last_seen_at = CURRENT_TIMESTAMP(3),
            updated_at = CURRENT_TIMESTAMP(3)
        """,
        (key, display_name, region, zone, version, capabilities_json, connectivity, clock_skew, ip_hash),
    )
    registered = db.query_one("SELECT * FROM monitoring_nodes WHERE node_key = %s LIMIT 1", (key,))
    if registered is None:
        raise RuntimeError("Unable to register node.")
    return registered


def remember_nonce(db: Database, node_id: int, nonce: str) -> None:
    nonce_hash = hashlib.sha256(nonce.encode("utf-8")).hexdigest()
    try:
        db.execute(
            "INSERT INTO monitoring_agent_requests (node_id, nonce_hash) VALUES (%s, %s)",
            (node_id, nonce_hash),
        )
    except Exception as exc:
        if "duplicate" in str(exc).lower() or "1062" in str(exc):
            raise DistributedError("Agent request already received.", 409) from exc
        raise
    retention = env_int("INSIGHT_AGENT_NONCE_RETENTION_SEC", 86400, 3600, 604800)
    db.execute(
        "DELETE FROM monitoring_agent_requests WHERE received_at < %s",
        (_format_datetime(datetime.now() - timedelta(seconds=retention)),),
    )


def rendezvous_nodes(site_id: int, nodes: list[dict[str, Any]], desired: int) -> list[dict[str, Any]]:
    limit = len(nodes) if desired <= 0 else min(len(nodes), desired)
    scored = []
    for node in nodes:
        node_key = str(node.get("node_key") or "")
        if node_key:
            score = hashlib.sha256(f"insight-assignment-v1|{site_id}|{node_key}".encode("utf-8")).hexdigest()
            scored.append((score, node_key, node))
    scored.sort(key=lambda item: item[1])
    scored.sort(key=lambda item: item[0], reverse=True)
    return [dict(item[2]) for item in scored[:limit]]


def refresh_assignments(db: Database) -> dict[str, int]:
    nodes = db.query_all("SELECT id, node_key FROM monitoring_nodes WHERE status = 'active' ORDER BY node_key")
    sites = db.query_all("SELECT id, probe_replication_factor FROM sites WHERE active = 1 AND probe_type <> 'heartbeat' ORDER BY id")
    default_replicas = env_int("INSIGHT_AGENT_DEFAULT_REPLICAS", 3, 0, 1000)
    pairs: list[tuple[int, int]] = []
    for site in sites:
        site_id = int(site.get("id") or 0)
        configured = int(site.get("probe_replication_factor") or 0)
        desired = configured if configured > 0 else default_replicas
        pairs.extend((site_id, int(node["id"])) for node in rendezvous_nodes(site_id, nodes, desired))
    db.begin()
    try:
        db.execute("UPDATE monitoring_assignments SET active = 0 WHERE active <> 0")
        for site_id, node_id in pairs:
            db.execute(
                """
                INSERT INTO monitoring_assignments (site_id, node_id, active) VALUES (%s, %s, 1)
                ON DUPLICATE KEY UPDATE active = 1, updated_at = CURRENT_TIMESTAMP(3)
                """,
                (site_id, node_id),
            )
        db.commit()
    except Exception:
        db.rollback()
        raise
    return {"nodes": len(nodes), "sites": len(sites), "assignments": len(pairs)}


def config_for_node(db: Database, node: dict[str, Any]) -> dict[str, Any]:
    refresh_assignments(db)
    rows = db.query_all(
        """
        SELECT
            s.id, s.url, s.probe_type, s.probe_interval_sec, s.http_methods,
            s.http_redirect_modes, s.http_primary_method, s.http_primary_redirect,
            s.timeout_sec, s.retry_count, s.accepted_status_codes, s.keyword_text,
            s.keyword_mode, s.json_path, s.json_expected_value, s.request_headers_json,
            s.request_body, s.basic_auth_username, s.basic_auth_password_ciphertext, s.probe_config_ciphertext,
            s.tls_verify, s.dns_record_type, s.dns_expected_value,
            s.probe_success_quorum, s.probe_failure_quorum,
            rw.ends_at AS reinforced_ends_at, rw.interval_sec AS reinforced_interval_sec
        FROM monitoring_assignments a
        INNER JOIN sites s ON s.id = a.site_id
        LEFT JOIN monitoring_reinforced_watch rw ON rw.site_id = s.id AND rw.ends_at > CURRENT_TIMESTAMP(3)
        WHERE a.node_id = %s AND a.active = 1 AND s.active = 1 AND s.probe_type <> 'heartbeat'
          AND (s.probe_type <> 'service' OR s.url LIKE CONCAT('agent://', %s, '/%%'))
        ORDER BY s.id
        """,
        (int(node["id"]), str(node["node_key"])),
    )
    targets = []
    for row in rows:
        password = ""
        ciphertext = str(row.get("basic_auth_password_ciphertext") or "")
        if ciphertext:
            try:
                password = str(decrypt_config(ciphertext).get("password") or "")
            except Exception:
                password = ""
        probe_config: dict[str, Any] = {}
        encrypted_probe_config = str(row.get("probe_config_ciphertext") or "")
        if encrypted_probe_config:
            try:
                candidate = decrypt_config(encrypted_probe_config)
                probe_config = candidate if isinstance(candidate, dict) else {}
            except Exception:
                probe_config = {}
        targets.append({
            "site_id": int(row["id"]),
            "url": str(row["url"]),
            "probe_type": str(row.get("probe_type") or "http"),
            "interval_sec": effective_interval(
                max(10, int(row.get("probe_interval_sec") or 60)),
                {
                    "ends_at": row.get("reinforced_ends_at"),
                    "interval_sec": row.get("reinforced_interval_sec"),
                },
            ),
            "reinforced_until": str(row.get("reinforced_ends_at")) if row.get("reinforced_ends_at") else None,
            "http_methods": str(row.get("http_methods") or "GET"),
            "http_redirect_modes": str(row.get("http_redirect_modes") or "follow"),
            "http_primary_method": str(row.get("http_primary_method") or "GET"),
            "http_primary_redirect": str(row.get("http_primary_redirect") or "follow"),
            "timeout_sec": int(row.get("timeout_sec") or 10),
            "retry_count": int(row.get("retry_count") or 0),
            "accepted_status_codes": str(row.get("accepted_status_codes") or "200-399"),
            "keyword_text": str(row.get("keyword_text") or ""),
            "keyword_mode": str(row.get("keyword_mode") or "none"),
            "json_path": str(row.get("json_path") or ""),
            "json_expected_value": str(row.get("json_expected_value") or ""),
            "request_headers_json": str(row.get("request_headers_json") or ""),
            "request_body": str(row.get("request_body") or ""),
            "basic_auth_username": str(row.get("basic_auth_username") or ""),
            "basic_auth_password": password,
            "probe_config": probe_config,
            "tls_verify": bool(row.get("tls_verify")),
            "dns_record_type": str(row.get("dns_record_type") or "A"),
            "dns_expected_value": str(row.get("dns_expected_value") or ""),
            "success_quorum": int(row.get("probe_success_quorum") or 0),
            "failure_quorum": int(row.get("probe_failure_quorum") or 0),
        })
    config_hash = hashlib.sha256(json.dumps(targets, separators=(",", ":")).encode("utf-8")).hexdigest()
    db.execute(
        "UPDATE monitoring_nodes SET last_config_at = CURRENT_TIMESTAMP(3), last_seen_at = CURRENT_TIMESTAMP(3) WHERE id = %s",
        (int(node["id"]),),
    )
    return {
        "config_version": config_hash,
        "generated_at": datetime.now(timezone.utc).isoformat().replace("+00:00", "Z"),
        "refresh_after_sec": env_int("INSIGHT_AGENT_CONFIG_REFRESH_SEC", 60, 10, 3600),
        "batch_size": env_int("INSIGHT_AGENT_BATCH_SIZE", 200, 1, 1000),
        "targets": targets,
    }


def parse_observed_at(value: Any) -> str:
    parsed = _database_datetime(value)
    if parsed is None:
        raise ValueError("Invalid timestamp.")
    now = datetime.now()
    max_age_days = env_int("INSIGHT_AGENT_MAX_SAMPLE_AGE_DAYS", 7, 1, 90)
    if parsed < now - timedelta(days=max_age_days) or parsed > now + timedelta(minutes=5):
        raise ValueError("Timestamp is outside the accepted window.")
    return _format_datetime(parsed, milliseconds=True)


def percentile(values: list[float], ratio: float) -> float | None:
    if not values:
        return None
    ordered = sorted(values)
    index = max(0, min(len(ordered) - 1, math.ceil(ratio * len(ordered)) - 1))
    return round(float(ordered[index]), 3)


def consensus_from_observations(
    observations: list[dict[str, Any]],
    expected: int,
    configured_success_quorum: int = 0,
    configured_failure_quorum: int = 0,
) -> dict[str, Any]:
    expected = max(0, int(expected))
    majority = max(1, expected // 2 + 1)
    success_quorum = min(max(1, configured_success_quorum), max(1, expected)) if configured_success_quorum > 0 else majority
    failure_quorum = min(max(1, configured_failure_quorum), max(1, expected)) if configured_failure_quorum > 0 else majority
    counts = {"online": 0, "offline": 0, "degraded": 0}
    response_times: list[float] = []
    last_observation = None
    for observation in observations:
        status = str(observation.get("status") or "unknown").strip().lower()
        if status in counts:
            counts[status] += 1
        if status in {"online", "degraded"} and observation.get("response_time_ms") is not None:
            try:
                response_times.append(max(0.0, float(observation["response_time_ms"])))
            except Exception:
                pass
        observed_at = _database_datetime(observation.get("observed_at"))
        if observed_at is not None and (last_observation is None or observed_at > last_observation):
            last_observation = observed_at
    fresh = sum(counts.values())
    missing = max(0, expected - fresh)
    if expected <= 0 or fresh <= 0:
        status = "unknown"
    elif counts["offline"] >= failure_quorum:
        status = "offline"
    elif counts["online"] >= success_quorum and counts["offline"] == 0 and counts["degraded"] == 0:
        status = "online"
    elif fresh < min(success_quorum, failure_quorum):
        status = "unknown"
    else:
        status = "degraded"
    winner = counts.get(status, max(counts.values()) if status == "degraded" else 0)
    return {
        "status": status,
        "nodes_expected": expected,
        "nodes_fresh": fresh,
        "nodes_online": counts["online"],
        "nodes_offline": counts["offline"],
        "nodes_degraded": counts["degraded"],
        "nodes_missing": missing,
        "success_quorum": success_quorum,
        "failure_quorum": failure_quorum,
        "confidence": round(winner / expected, 5) if expected > 0 else 0.0,
        "response_median_ms": percentile(response_times, 0.5),
        "response_p95_ms": percentile(response_times, 0.95),
        "last_observation_at": last_observation,
    }


def _site_under_maintenance(db: Database, site_id: int) -> bool:
    try:
        row = db.query_one(
            """
            SELECT m.id
            FROM scheduled_maintenances m
            WHERE m.status = 'planned'
              AND m.starts_at <= CURRENT_TIMESTAMP
              AND m.ends_at >= CURRENT_TIMESTAMP
              AND (
                  m.site_id = %s
                  OR EXISTS (
                      SELECT 1 FROM maintenance_sites ms
                      WHERE ms.maintenance_id = m.id AND ms.site_id = %s
                  )
                  OR (
                      m.site_id IS NULL
                      AND NOT EXISTS (
                          SELECT 1 FROM maintenance_sites scope
                          WHERE scope.maintenance_id = m.id
                      )
                  )
              )
            LIMIT 1
            """,
            (site_id, site_id),
        )
    except Exception:
        return False
    return row is not None


def _incident_context(
    cfg: dict[str, Any],
    incident_id: int,
    site_id: int,
    site_url: str,
    consensus: dict[str, Any],
    message: str,
    status: str,
) -> dict[str, Any]:
    host = urlparse(site_url if "://" in site_url else f"https://{site_url}").hostname or site_url
    return {
        "app_name": str(cfg.get("app_name") or "Insight"),
        "public_url": str(cfg.get("public_url") or ""),
        "id": incident_id,
        "incident_id": incident_id,
        "site_id": site_id,
        "site_url": site_url,
        "sites": site_url,
        "domain": host,
        "status": status,
        "severity": "major" if status == "offline" else "info",
        "message": message,
        "nodes_expected": int(consensus.get("nodes_expected") or 0),
        "nodes_fresh": int(consensus.get("nodes_fresh") or 0),
        "confidence": float(consensus.get("confidence") or 0),
    }


def _update_incident(
    db: Database,
    site_id: int,
    site_url: str,
    consensus: dict[str, Any],
    bucket_at: str,
    cfg: dict[str, Any] | None = None,
) -> str:
    if not env_bool("INSIGHT_DISTRIBUTED_INCIDENTS", True):
        return "disabled"
    status = str(consensus["status"])
    if status not in {"online", "offline"}:
        return "none"
    setting = "INSIGHT_CONSENSUS_FAILURE_WINDOWS" if status == "offline" else "INSIGHT_CONSENSUS_RECOVERY_WINDOWS"
    required = env_int(setting, 2, 1, 20)
    statuses = db.query_all(
        f"SELECT status FROM monitoring_consensus_snapshots WHERE site_id = %s ORDER BY bucket_at DESC LIMIT {required}",
        (site_id,),
    )
    if len(statuses) < required or any(str(row.get("status")) != status for row in statuses):
        return "none"
    open_incident = db.query_one(
        "SELECT id, started_at FROM incidents WHERE site_id = %s AND ended_at IS NULL AND (resolved IS NULL OR resolved = 0) ORDER BY started_at DESC LIMIT 1",
        (site_id,),
    )
    if status == "offline" and open_incident is None:
        if _site_under_maintenance(db, site_id):
            return "maintenance"
        incident_code = f"DST-{site_id}-{datetime.strptime(bucket_at, '%Y-%m-%d %H:%M:%S').strftime('%Y%m%d%H%M%S')}"
        host = urlparse(site_url if "://" in site_url else f"https://{site_url}").hostname or site_url
        summary = (
            f"Distributed consensus confirmed an outage from {int(consensus.get('nodes_offline') or 0)} "
            f"of {int(consensus.get('nodes_expected') or 0)} assigned agents."
        )
        incident_id = db.insert(
            """
            INSERT INTO incidents
                (site_id, incident_code, title, summary, severity, lifecycle_status, started_at, incident_date,
                 source_mode, site_label, resolved, status, published)
            VALUES (%s, %s, %s, %s, 'major', 'started', %s, %s, 'system', %s, 0, 0, 1)
            """,
            (site_id, incident_code, f"Service unavailable: {host}"[:200], summary[:2000], bucket_at, bucket_at, site_url[:255]),
        )
        db.execute("INSERT IGNORE INTO incident_sites (incident_id, site_id) VALUES (%s, %s)", (incident_id, site_id))
        message = "Distributed monitoring confirmed an interruption. Investigation is in progress."
        db.execute(
            "INSERT INTO incident_updates (incident_id, lifecycle_status, message, is_public, author_name) VALUES (%s, 'started', %s, 1, 'Insight')",
            (incident_id, message),
        )
        try:
            dispatch_event(
                db,
                cfg or {},
                "incident_open",
                _incident_context(cfg or {}, incident_id, site_id, site_url, consensus, message, "offline"),
                idempotency_key=f"incident:{incident_id}:open",
            )
        except Exception:
            pass
        return "opened"
    elif status == "online" and open_incident is not None:
        incident_id = int(open_incident["id"])
        message = "The service is operational again. Enhanced monitoring is active."
        db.execute(
            "UPDATE incidents SET ended_at = %s, resolved = 1, status = 1, lifecycle_status = 'resolved', resolved_by = 'Insight', updated_at = CURRENT_TIMESTAMP WHERE id = %s",
            (bucket_at, incident_id),
        )
        db.execute(
            "INSERT INTO incident_updates (incident_id, lifecycle_status, message, is_public, author_name) VALUES (%s, 'resolved', %s, 1, 'Insight')",
            (incident_id, message),
        )
        activate_reinforced_watch(db, site_id, incident_id, "consensus", cfg)
        try:
            dispatch_event(
                db,
                cfg or {},
                "incident_resolved",
                _incident_context(cfg or {}, incident_id, site_id, site_url, consensus, message, "online"),
                idempotency_key=f"incident:{incident_id}:resolved",
            )
        except Exception:
            pass
        return "resolved"
    return "none"


def evaluate_site(db: Database, site_id: int, cfg: dict[str, Any] | None = None) -> dict[str, Any]:
    site = db.query_one(
        """
        SELECT s.id, s.url, s.probe_interval_sec, s.probe_success_quorum, s.probe_failure_quorum,
               rw.ends_at AS reinforced_ends_at, rw.interval_sec AS reinforced_interval_sec
        FROM sites s
        LEFT JOIN monitoring_reinforced_watch rw ON rw.site_id = s.id AND rw.ends_at > CURRENT_TIMESTAMP(3)
        WHERE s.id = %s
        LIMIT 1
        """,
        (site_id,),
    )
    if site is None:
        raise ValueError("Distributed site not found.")
    interval = max(10, int(site.get("probe_interval_sec") or 60))
    reinforced_watch = {
        "ends_at": site.get("reinforced_ends_at"),
        "interval_sec": site.get("reinforced_interval_sec"),
    }
    reinforced_active = watch_is_active(reinforced_watch)
    interval = effective_interval(interval, reinforced_watch)
    freshness = max(env_int("INSIGHT_CONSENSUS_FRESHNESS_SEC", 180, 30, 86400), interval * 3)
    cutoff = datetime.now() - timedelta(seconds=freshness)
    rows = db.query_all(
        """
        SELECT a.node_id, o.status, o.response_time_ms, o.observed_at
        FROM monitoring_assignments a
        LEFT JOIN monitoring_observations o ON o.id = (
            SELECT o2.id
            FROM monitoring_observations o2
            WHERE o2.site_id = a.site_id AND o2.node_id = a.node_id
            ORDER BY o2.observed_at DESC, o2.id DESC
            LIMIT 1
        )
        WHERE a.site_id = %s AND a.active = 1
        ORDER BY a.node_id
        """,
        (site_id,),
    )
    fresh_rows = [row for row in rows if (_database_datetime(row.get("observed_at")) or datetime.min) >= cutoff]
    consensus = consensus_from_observations(
        fresh_rows,
        len(rows),
        int(site.get("probe_success_quorum") or 0),
        int(site.get("probe_failure_quorum") or 0),
    )
    bucket_seconds = env_int("INSIGHT_CONSENSUS_BUCKET_SEC", 60, 10, 3600)
    if reinforced_active:
        bucket_seconds = min(bucket_seconds, interval)
    bucket_epoch = int(time.time()) // bucket_seconds * bucket_seconds
    bucket_at = _format_datetime(datetime.fromtimestamp(bucket_epoch))
    values = (
        site_id,
        consensus["status"],
        consensus["nodes_expected"],
        consensus["nodes_fresh"],
        consensus["nodes_online"],
        consensus["nodes_offline"],
        consensus["nodes_degraded"],
        consensus["nodes_missing"],
        consensus["success_quorum"],
        consensus["failure_quorum"],
        consensus["confidence"],
        consensus["response_median_ms"],
        consensus["response_p95_ms"],
        _format_datetime(cutoff, milliseconds=True),
        consensus["last_observation_at"],
    )
    db.execute(
        """
        INSERT INTO monitoring_consensus_current
            (site_id, status, nodes_expected, nodes_fresh, nodes_online, nodes_offline, nodes_degraded, nodes_missing,
             success_quorum, failure_quorum, confidence, response_median_ms, response_p95_ms, window_started_at, last_observation_at)
        VALUES (%s, %s, %s, %s, %s, %s, %s, %s, %s, %s, %s, %s, %s, %s, %s)
        ON DUPLICATE KEY UPDATE
            status = VALUES(status), nodes_expected = VALUES(nodes_expected), nodes_fresh = VALUES(nodes_fresh),
            nodes_online = VALUES(nodes_online), nodes_offline = VALUES(nodes_offline), nodes_degraded = VALUES(nodes_degraded),
            nodes_missing = VALUES(nodes_missing), success_quorum = VALUES(success_quorum), failure_quorum = VALUES(failure_quorum),
            confidence = VALUES(confidence), response_median_ms = VALUES(response_median_ms), response_p95_ms = VALUES(response_p95_ms),
            window_started_at = VALUES(window_started_at), last_observation_at = VALUES(last_observation_at), evaluated_at = CURRENT_TIMESTAMP(3)
        """,
        values,
    )
    db.execute(
        """
        INSERT INTO monitoring_consensus_snapshots
            (site_id, bucket_at, status, nodes_expected, nodes_fresh, nodes_online, nodes_offline, nodes_degraded,
             nodes_missing, success_quorum, failure_quorum, confidence, response_median_ms, response_p95_ms)
        VALUES (%s, %s, %s, %s, %s, %s, %s, %s, %s, %s, %s, %s, %s, %s)
        ON DUPLICATE KEY UPDATE
            status = VALUES(status), nodes_expected = VALUES(nodes_expected), nodes_fresh = VALUES(nodes_fresh),
            nodes_online = VALUES(nodes_online), nodes_offline = VALUES(nodes_offline), nodes_degraded = VALUES(nodes_degraded),
            nodes_missing = VALUES(nodes_missing), success_quorum = VALUES(success_quorum), failure_quorum = VALUES(failure_quorum),
            confidence = VALUES(confidence), response_median_ms = VALUES(response_median_ms), response_p95_ms = VALUES(response_p95_ms),
            evaluated_at = CURRENT_TIMESTAMP(3)
        """,
        (site_id, bucket_at, *values[1:13]),
    )
    db.execute(
        """
        INSERT INTO probes
            (site_id, probe_type, status, response_time, http_code, checked_by, checked_at, source_node, source_probe_id)
        VALUES (%s, 'distributed', %s, %s, NULL, 'dst', %s, %s, %s)
        ON DUPLICATE KEY UPDATE status = VALUES(status), response_time = VALUES(response_time), checked_at = VALUES(checked_at)
        """,
        (site_id, consensus["status"], consensus["response_median_ms"], bucket_at, f"consensus:{site_id}", bucket_epoch),
    )
    incident_action = _update_incident(db, site_id, str(site["url"]), consensus, bucket_at, cfg)
    return {
        **consensus,
        "last_observation_at": consensus["last_observation_at"],
        "site_id": site_id,
        "bucket_at": bucket_at,
        "reinforced_monitoring": reinforced_active,
        "reinforced_until": site.get("reinforced_ends_at"),
        "effective_interval_sec": interval,
        "incident_action": incident_action,
    }


def evaluate_all(db: Database, cfg: dict[str, Any] | None = None) -> list[dict[str, Any]]:
    refresh_assignments(db)
    return [
        evaluate_site(db, int(row["id"]), cfg)
        for row in db.query_all("SELECT id FROM sites WHERE active = 1 AND probe_type <> 'heartbeat' ORDER BY id")
    ]


def _normalize_observation(observation: Any, valid_sites: set[int]) -> dict[str, Any]:
    if not isinstance(observation, dict):
        raise ValueError("Observation is not structured.")
    sample_id = str(observation.get("sample_id") or "").strip().lower()
    if re.fullmatch(r"[a-z0-9-]{16,64}", sample_id) is None:
        raise ValueError("Invalid sample identifier.")
    site_id = int(observation.get("site_id") or 0)
    if site_id not in valid_sites:
        raise ValueError("Unknown target.")
    status = str(observation.get("status") or "unknown").strip().lower()
    if status not in {"online", "offline", "degraded", "unknown"}:
        raise ValueError("Invalid status.")
    try:
        response_time = round(max(0.0, min(3600000.0, float(observation["response_time_ms"]))), 3)
    except Exception:
        response_time = None
    try:
        http_code = max(0, min(999, int(observation["http_code"])))
    except Exception:
        http_code = None
    metadata_value = observation.get("metadata")
    metadata = json.dumps(metadata_value, ensure_ascii=False, separators=(",", ":")) if isinstance(metadata_value, dict) and metadata_value else None
    if metadata is not None and len(metadata.encode("utf-8")) > 8192:
        metadata = None
    return {
        "site_id": site_id,
        "sample_id": sample_id,
        "status": status,
        "observed_at": parse_observed_at(observation.get("observed_at")),
        "response_time": response_time,
        "http_code": http_code,
        "error_code": _clean_text(observation.get("error_code"), 64),
        "error_message": _clean_text(observation.get("error_message"), 255),
        "metadata": metadata,
    }


def ingest_batch(
    db: Database,
    node: dict[str, Any],
    body: dict[str, Any],
    payload_sha256: str,
    cfg: dict[str, Any] | None = None,
) -> dict[str, Any]:
    node_id = int(node["id"])
    batch_id = str(body.get("batch_id") or "").strip().lower()
    if re.fullmatch(r"[a-f0-9-]{16,64}", batch_id) is None:
        raise DistributedError("Invalid batch identifier.", 422)
    observations = body.get("observations")
    if not isinstance(observations, list):
        raise DistributedError("Invalid observation list.", 422)
    if len(observations) > env_int("INSIGHT_AGENT_BATCH_SIZE", 200, 1, 1000):
        raise DistributedError("Observation batch is too large.", 413)
    existing = db.query_one(
        "SELECT payload_sha256, accepted_count, duplicate_count, rejected_count FROM monitoring_agent_batches WHERE node_id = %s AND batch_id = %s LIMIT 1",
        (node_id, batch_id),
    )
    if existing is not None:
        if not hmac.compare_digest(str(existing["payload_sha256"]), payload_sha256):
            raise DistributedError("This batch identifier already refers to different content.", 409)
        return {
            "batch_id": batch_id,
            "accepted": int(existing["accepted_count"]),
            "duplicates": int(existing["duplicate_count"]),
            "rejected": int(existing["rejected_count"]),
            "already_processed": True,
            "consensus": [],
        }
    valid_sites = {
        int(row["id"])
        for row in db.query_all(
            """
            SELECT s.id
            FROM monitoring_assignments a
            INNER JOIN sites s ON s.id = a.site_id
            WHERE a.node_id = %s AND a.active = 1 AND s.active = 1 AND s.probe_type <> 'heartbeat'
            """,
            (node_id,),
        )
    }
    accepted = 0
    duplicates = 0
    rejected = 0
    errors = []
    affected_sites: set[int] = set()
    db.begin()
    try:
        for index, raw_observation in enumerate(observations):
            try:
                observation = _normalize_observation(raw_observation, valid_sites)
                changed = db.execute(
                    """
                    INSERT IGNORE INTO monitoring_observations
                        (site_id, node_id, sample_id, batch_id, status, response_time_ms, http_code, error_code, error_message, metadata, observed_at)
                    VALUES (%s, %s, %s, %s, %s, %s, %s, %s, %s, %s, %s)
                    """,
                    (
                        observation["site_id"], node_id, observation["sample_id"], batch_id, observation["status"],
                        observation["response_time"], observation["http_code"], observation["error_code"],
                        observation["error_message"], observation["metadata"], observation["observed_at"],
                    ),
                )
                if changed > 0:
                    accepted += 1
                    affected_sites.add(int(observation["site_id"]))
                else:
                    duplicates += 1
            except Exception as exc:
                rejected += 1
                if len(errors) < 20:
                    errors.append({"index": index, "message": str(exc)})
        db.execute(
            """
            INSERT INTO monitoring_agent_batches
                (node_id, batch_id, payload_sha256, sample_count, accepted_count, duplicate_count, rejected_count)
            VALUES (%s, %s, %s, %s, %s, %s, %s)
            """,
            (node_id, batch_id, payload_sha256, len(observations), accepted, duplicates, rejected),
        )
        db.commit()
    except Exception:
        db.rollback()
        raise
    refresh_assignments(db)
    consensus = [evaluate_site(db, site_id, cfg) for site_id in sorted(affected_sites)]
    return {
        "batch_id": batch_id,
        "accepted": accepted,
        "duplicates": duplicates,
        "rejected": rejected,
        "already_processed": False,
        "errors": errors,
        "consensus": consensus,
    }


def cleanup(db: Database) -> dict[str, int]:
    settings = {
        "monitoring_observations": ("received_at", env_int("INSIGHT_AGENT_RAW_RETENTION_DAYS", 7, 1, 365)),
        "monitoring_consensus_snapshots": ("bucket_at", env_int("INSIGHT_CONSENSUS_RETENTION_DAYS", 90, 7, 3650)),
        "monitoring_agent_batches": ("received_at", env_int("INSIGHT_AGENT_BATCH_RETENTION_DAYS", 7, 1, 365)),
    }
    deleted = {}
    for table, (column, days) in settings.items():
        deleted[table] = max(
            0,
            db.execute(
                f"DELETE FROM `{table}` WHERE `{column}` < %s",
                (_format_datetime(datetime.now() - timedelta(days=days)),),
            ),
        )
    return deleted


def summary(db: Database) -> dict[str, Any]:
    cutoff = _format_datetime(datetime.now() - timedelta(seconds=env_int("INSIGHT_AGENT_NODE_TTL_SEC", 180, 30, 86400)))
    nodes = db.query_one(
        """
        SELECT COUNT(*) AS total,
               SUM(CASE WHEN status = 'active' AND last_seen_at >= %s THEN 1 ELSE 0 END) AS live,
               SUM(CASE WHEN status = 'active' AND last_seen_at < %s THEN 1 ELSE 0 END) AS stale,
               SUM(CASE WHEN status = 'revoked' THEN 1 ELSE 0 END) AS revoked
        FROM monitoring_nodes
        """,
        (cutoff, cutoff),
    ) or {}
    consensus = db.query_one(
        """
        SELECT COUNT(*) AS total, SUM(status = 'online') AS online, SUM(status = 'offline') AS offline,
               SUM(status = 'degraded') AS degraded, SUM(status = 'unknown') AS unknown,
               MAX(evaluated_at) AS last_evaluated_at
        FROM monitoring_consensus_current
        """
    ) or {}
    reinforced = db.query_one(
        """
        SELECT COUNT(*) AS active, MIN(ends_at) AS next_end_at, MAX(ends_at) AS last_end_at
        FROM monitoring_reinforced_watch
        WHERE ends_at > CURRENT_TIMESTAMP(3)
        """
    ) or {}
    return {
        "nodes": {key: int(nodes.get(key) or 0) for key in ("total", "live", "stale", "revoked")},
        "consensus": {
            **{key: int(consensus.get(key) or 0) for key in ("total", "online", "offline", "degraded", "unknown")},
            "last_evaluated_at": consensus.get("last_evaluated_at"),
        },
        "reinforced_monitoring": {
            "active": int(reinforced.get("active") or 0),
            "next_end_at": reinforced.get("next_end_at"),
            "last_end_at": reinforced.get("last_end_at"),
        },
    }


def process_agent_request(
    db: Database,
    raw_body: str,
    node_key: str,
    timestamp: str,
    nonce: str,
    signature: str,
    remote_address: str,
    cfg: dict[str, Any] | None = None,
) -> dict[str, Any]:
    key = verify_signature(node_key, timestamp, nonce, signature, raw_body)
    try:
        body = json.loads(raw_body)
    except json.JSONDecodeError as exc:
        raise DistributedError("Invalid agent JSON.", 400) from exc
    if not isinstance(body, dict):
        raise DistributedError("Invalid agent JSON.", 400)
    action = str(body.get("action") or "").strip().lower()
    if action not in {"heartbeat", "config", "ingest"}:
        raise DistributedError("Invalid agent action.", 400)
    node = register_node(db, key, body, remote_address)
    remember_nonce(db, int(node["id"]), nonce)
    node_payload = {"node_key": str(node["node_key"]), "status": str(node["status"])}
    if action == "heartbeat":
        return {"status": "success", "message": "Heartbeat received.", "node": node_payload, "summary": summary(db)}
    if action == "config":
        return {"status": "success", "message": "Distributed configuration generated.", "node": node_payload, "config": config_for_node(db, node)}
    result = ingest_batch(db, node, body, hashlib.sha256(raw_body.encode("utf-8")).hexdigest(), cfg)
    return {"status": "success", "message": "Agent batch processed.", "result": result}


def run_consensus_job(db: Database, cfg: dict[str, Any] | None = None) -> dict[str, Any]:
    values = cfg or {}
    logger = logging.getLogger("insight.consensus")
    maintenance_stats = {"started": 0, "completed": 0, "advanced": 0}
    try:
        from .monitor import advance_scheduled_maintenances

        maintenance_stats = advance_scheduled_maintenances(db, values, logger)
    except Exception:
        pass
    results = evaluate_all(db, values)
    errors = sum(1 for result in results if str(result.get("status")) != "online")
    oncall_stats = {"due": 0, "sent": 0, "failed": 0, "without_shift": 0, "disabled": 0}
    try:
        oncall_stats = apply_oncall_escalations(db, values)
    except Exception:
        pass
    return {
        "ok": True,
        "engine": "consensus",
        "evaluated": len(results),
        "sites_checked": len(results),
        "errors": errors,
        "incidents_opened": sum(1 for result in results if result.get("incident_action") == "opened"),
        "incidents_closed": sum(1 for result in results if result.get("incident_action") == "resolved"),
        "sites_under_maintenance": sum(1 for result in results if result.get("incident_action") == "maintenance"),
        "maintenances": maintenance_stats,
        "oncall": oncall_stats,
        "escalation": oncall_stats,
        "results": results,
    }


def list_nodes(db: Database) -> dict[str, Any]:
    rows = db.query_all(
        """
        SELECT n.node_key, n.display_name, COALESCE(n.region, '-') AS region, COALESCE(n.zone, '-') AS zone,
               n.status, n.connectivity_status, n.last_seen_at,
               (SELECT COUNT(*) FROM monitoring_assignments a WHERE a.node_id = n.id AND a.active = 1) AS assignments
        FROM monitoring_nodes n
        ORDER BY n.node_key
        """
    )
    return {"ok": True, "nodes": rows}


def provision_node(
    db: Database,
    node_key: str,
    display_name: str = "",
    region: str = "",
    zone: str = "",
) -> dict[str, Any]:
    key = validate_node_key(node_key)
    name = _clean_text(display_name or key, 120) or key
    normalized_region = _clean_text(region, 64)
    normalized_zone = _clean_text(zone, 64)
    if db.query_one("SELECT id FROM monitoring_nodes WHERE node_key = %s LIMIT 1", (key,)) is not None:
        raise DistributedError("An agent already uses this identifier.", 409)
    db.execute(
        """
        INSERT INTO monitoring_nodes
            (node_key, display_name, region, zone, status, connectivity_status, last_seen_at)
        VALUES (%s, %s, %s, %s, 'active', 'unknown', NULL)
        """,
        (key, name, normalized_region, normalized_zone),
    )
    refresh_assignments(db)
    return {
        "ok": True,
        "node_key": key,
        "display_name": name,
        "region": normalized_region or "",
        "zone": normalized_zone or "",
        "secret": derive_node_secret(key),
        "status": "active",
    }


def set_node_status(db: Database, node_key: str, status: str) -> dict[str, Any]:
    key = validate_node_key(node_key)
    if status not in {"active", "paused", "revoked"}:
        raise ValueError("Invalid node status.")
    changed = db.execute(
        "UPDATE monitoring_nodes SET status = %s, updated_at = CURRENT_TIMESTAMP(3) WHERE node_key = %s AND status <> %s",
        (status, key, status),
    )
    if changed < 1:
        raise DistributedError("Node not found or already in this state.", 404)
    refresh_assignments(db)
    return {"ok": True, "node_key": key, "status": status}
