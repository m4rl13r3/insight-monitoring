#!/usr/bin/env python3

from __future__ import annotations

import argparse
import hashlib
import hmac
import json
import os
import random
import re
import secrets
import socket
import sqlite3
import ssl
import sys
import time
import uuid
from concurrent.futures import ThreadPoolExecutor, as_completed
from datetime import datetime, timezone
from pathlib import Path
from typing import Any
from urllib.error import HTTPError, URLError
from urllib.parse import parse_qsl, urlencode, urlsplit, urlunsplit
from urllib.request import Request, urlopen

sys.path.insert(0, str(Path(__file__).resolve().parents[1]))

from python_monitoring.monitor import run_manual_check


VERSION = "0.1.1"


def env_bool(name: str, default: bool = False) -> bool:
    raw = os.getenv(name)
    if raw is None:
        return default
    return raw.strip().lower() in {"1", "true", "yes", "on"}


def env_int(name: str, default: int, minimum: int, maximum: int) -> int:
    try:
        value = int((os.getenv(name) or str(default)).strip())
    except ValueError:
        value = default
    return max(minimum, min(maximum, value))


def now_iso() -> str:
    return datetime.now(timezone.utc).isoformat(timespec="milliseconds").replace("+00:00", "Z")


def log(message: str, level: str = "info") -> None:
    timestamp = datetime.now().astimezone().isoformat(timespec="seconds")
    print(f"[{timestamp}] {level.upper()}: {message}", flush=True)


def compact_json(value: Any) -> str:
    return json.dumps(value, ensure_ascii=False, separators=(",", ":"), sort_keys=True)


class AgentHttpError(RuntimeError):
    def __init__(self, status: int, message: str):
        super().__init__(message)
        self.status = status


class Spool:
    def __init__(self, path: Path, maximum_samples: int):
        path.parent.mkdir(parents=True, exist_ok=True)
        self.connection = sqlite3.connect(path)
        self.connection.row_factory = sqlite3.Row
        self.maximum_samples = maximum_samples
        self.connection.executescript(
            """
            PRAGMA journal_mode=WAL;
            PRAGMA synchronous=NORMAL;
            CREATE TABLE IF NOT EXISTS agent_state (
                state_key TEXT PRIMARY KEY,
                state_value TEXT NOT NULL
            );
            CREATE TABLE IF NOT EXISTS probe_schedule (
                site_id INTEGER PRIMARY KEY,
                next_due_at REAL NOT NULL
            );
            CREATE TABLE IF NOT EXISTS observations (
                sample_id TEXT PRIMARY KEY,
                site_id INTEGER NOT NULL,
                payload_json TEXT NOT NULL,
                batch_id TEXT,
                created_at REAL NOT NULL
            );
            CREATE INDEX IF NOT EXISTS idx_observations_batch
                ON observations (batch_id, created_at);
            CREATE TABLE IF NOT EXISTS outbound_batches (
                batch_id TEXT PRIMARY KEY,
                payload_json TEXT NOT NULL,
                attempts INTEGER NOT NULL DEFAULT 0,
                next_attempt_at REAL NOT NULL,
                created_at REAL NOT NULL
            );
            """
        )

    def close(self) -> None:
        self.connection.close()

    def get_state(self, key: str) -> str | None:
        row = self.connection.execute(
            "SELECT state_value FROM agent_state WHERE state_key = ?",
            (key,),
        ).fetchone()
        return str(row["state_value"]) if row else None

    def set_state(self, key: str, value: str) -> None:
        self.connection.execute(
            """
            INSERT INTO agent_state (state_key, state_value) VALUES (?, ?)
            ON CONFLICT(state_key) DO UPDATE SET state_value = excluded.state_value
            """,
            (key, value),
        )
        self.connection.commit()

    def sync_schedule(self, site_ids: set[int]) -> None:
        if not site_ids:
            self.connection.execute("DELETE FROM probe_schedule")
        else:
            placeholders = ",".join("?" for _ in site_ids)
            self.connection.execute(
                f"DELETE FROM probe_schedule WHERE site_id NOT IN ({placeholders})",
                tuple(sorted(site_ids)),
            )
        self.connection.commit()

    def is_due(self, site_id: int, current_time: float) -> bool:
        row = self.connection.execute(
            "SELECT next_due_at FROM probe_schedule WHERE site_id = ?",
            (site_id,),
        ).fetchone()
        return row is None or float(row["next_due_at"]) <= current_time

    def schedule(self, site_id: int, next_due_at: float) -> None:
        self.connection.execute(
            """
            INSERT INTO probe_schedule (site_id, next_due_at) VALUES (?, ?)
            ON CONFLICT(site_id) DO UPDATE SET next_due_at = excluded.next_due_at
            """,
            (site_id, next_due_at),
        )
        self.connection.commit()

    def enqueue(self, observation: dict[str, Any]) -> int:
        self.connection.execute(
            """
            INSERT OR IGNORE INTO observations
                (sample_id, site_id, payload_json, batch_id, created_at)
            VALUES (?, ?, ?, NULL, ?)
            """,
            (
                observation["sample_id"],
                int(observation["site_id"]),
                compact_json(observation),
                time.time(),
            ),
        )
        total = int(self.connection.execute("SELECT COUNT(*) FROM observations").fetchone()[0])
        excess = max(0, total - self.maximum_samples)
        dropped = 0
        if excess > 0:
            cursor = self.connection.execute(
                """
                DELETE FROM observations
                WHERE sample_id IN (
                    SELECT sample_id FROM observations
                    WHERE batch_id IS NULL
                    ORDER BY created_at ASC
                    LIMIT ?
                )
                """,
                (excess,),
            )
            dropped = max(0, int(cursor.rowcount))
        self.connection.commit()
        return dropped

    def prepare_batch(
        self,
        node_key: str,
        node: dict[str, Any],
        batch_size: int,
    ) -> str | None:
        existing = int(self.connection.execute("SELECT COUNT(*) FROM outbound_batches").fetchone()[0])
        if existing > 0:
            return None
        rows = self.connection.execute(
            """
            SELECT sample_id, payload_json FROM observations
            WHERE batch_id IS NULL
            ORDER BY created_at ASC
            LIMIT ?
            """,
            (batch_size,),
        ).fetchall()
        if not rows:
            return None
        sample_ids = [str(row["sample_id"]) for row in rows]
        batch_id = hashlib.sha256(
            f"{node_key}|{'|'.join(sample_ids)}".encode("utf-8")
        ).hexdigest()
        payload = {
            "action": "ingest",
            "batch_id": batch_id,
            "node": node,
            "observations": [json.loads(str(row["payload_json"])) for row in rows],
            "sent_at_ms": int(time.time() * 1000),
        }
        payload_json = compact_json(payload)
        with self.connection:
            self.connection.execute(
                """
                INSERT OR IGNORE INTO outbound_batches
                    (batch_id, payload_json, attempts, next_attempt_at, created_at)
                VALUES (?, ?, 0, ?, ?)
                """,
                (batch_id, payload_json, time.time(), time.time()),
            )
            self.connection.executemany(
                "UPDATE observations SET batch_id = ? WHERE sample_id = ? AND batch_id IS NULL",
                [(batch_id, sample_id) for sample_id in sample_ids],
            )
        return batch_id

    def next_batch(self, current_time: float) -> sqlite3.Row | None:
        return self.connection.execute(
            """
            SELECT batch_id, payload_json, attempts FROM outbound_batches
            WHERE next_attempt_at <= ?
            ORDER BY created_at ASC
            LIMIT 1
            """,
            (current_time,),
        ).fetchone()

    def complete_batch(self, batch_id: str) -> None:
        with self.connection:
            self.connection.execute("DELETE FROM observations WHERE batch_id = ?", (batch_id,))
            self.connection.execute("DELETE FROM outbound_batches WHERE batch_id = ?", (batch_id,))

    def retry_batch(self, batch_id: str, attempts: int) -> float:
        delay = min(300.0, (2 ** min(attempts, 8)) + random.uniform(0.25, 3.0))
        self.connection.execute(
            """
            UPDATE outbound_batches
            SET attempts = ?, next_attempt_at = ?
            WHERE batch_id = ?
            """,
            (attempts, time.time() + delay, batch_id),
        )
        self.connection.commit()
        return delay

    def stats(self) -> dict[str, int]:
        samples = int(self.connection.execute("SELECT COUNT(*) FROM observations").fetchone()[0])
        batches = int(self.connection.execute("SELECT COUNT(*) FROM outbound_batches").fetchone()[0])
        return {"samples": samples, "batches": batches}


class HubClient:
    def __init__(self, endpoint: str, node_key: str, secret: str, timeout: int, verify_tls: bool):
        self.endpoint = endpoint
        self.node_key = node_key
        self.secret = secret
        self.timeout = timeout
        self.context = ssl.create_default_context() if verify_tls else ssl._create_unverified_context()

    def post_payload(self, payload: dict[str, Any]) -> dict[str, Any]:
        return self.post_raw(compact_json(payload))

    def post_raw(self, raw_payload: str) -> dict[str, Any]:
        raw = raw_payload.encode("utf-8")
        timestamp = str(int(time.time()))
        nonce = secrets.token_hex(16)
        digest = hashlib.sha256(raw).hexdigest()
        canonical = f"v1\n{self.node_key}\n{timestamp}\n{nonce}\n{digest}"
        signature = hmac.new(
            self.secret.encode("utf-8"),
            canonical.encode("utf-8"),
            hashlib.sha256,
        ).hexdigest()
        request = Request(
            self.endpoint,
            data=raw,
            method="POST",
            headers={
                "Content-Type": "application/json",
                "User-Agent": f"Insight-Agent/{VERSION}",
                "X-Insight-Node": self.node_key,
                "X-Insight-Timestamp": timestamp,
                "X-Insight-Nonce": nonce,
                "X-Insight-Signature": signature,
            },
        )
        try:
            with urlopen(request, timeout=self.timeout, context=self.context) as response:
                response_body = response.read().decode("utf-8")
        except HTTPError as error:
            response_body = error.read().decode("utf-8", errors="replace")
            try:
                message = str(json.loads(response_body).get("message") or error.reason)
            except Exception:
                message = str(error.reason)
            raise AgentHttpError(int(error.code), message) from error
        except (URLError, TimeoutError, OSError) as error:
            raise AgentHttpError(0, str(error)) from error
        try:
            result = json.loads(response_body)
        except json.JSONDecodeError as error:
            raise AgentHttpError(0, "Réponse JSON invalide du hub.") from error
        if not isinstance(result, dict):
            raise AgentHttpError(0, "Réponse du hub non structurée.")
        return result


class InsightAgent:
    def __init__(self, once: bool = False):
        self.once = once
        self.hub_url = (os.getenv("INSIGHT_HUB_URL") or "").strip().rstrip("/")
        self.endpoint = (os.getenv("INSIGHT_AGENT_ENDPOINT") or "").strip()
        if not self.endpoint and self.hub_url:
            self.endpoint = f"{self.hub_url}/api/agent.php"
        self.node_key = (os.getenv("INSIGHT_AGENT_NODE_KEY") or "").strip().lower()
        self.secret = (os.getenv("INSIGHT_AGENT_SECRET") or "").strip()
        if not self.endpoint:
            raise RuntimeError("INSIGHT_HUB_URL ou INSIGHT_AGENT_ENDPOINT est requis.")
        if re.fullmatch(r"[a-z0-9][a-z0-9._-]{2,63}", self.node_key) is None:
            raise RuntimeError("INSIGHT_AGENT_NODE_KEY est invalide.")
        if len(self.secret) < 32:
            raise RuntimeError("INSIGHT_AGENT_SECRET doit contenir au moins 32 caractères.")
        self.display_name = (os.getenv("INSIGHT_AGENT_DISPLAY_NAME") or self.node_key).strip()
        self.region = (os.getenv("INSIGHT_AGENT_REGION") or "").strip()
        self.zone = (os.getenv("INSIGHT_AGENT_ZONE") or "").strip()
        self.connectivity_target = (os.getenv("INSIGHT_AGENT_CONNECTIVITY_TARGET") or "").strip()
        self.connectivity_timeout = env_int("INSIGHT_AGENT_CONNECTIVITY_TIMEOUT_SEC", 3, 1, 30)
        self.concurrency = env_int("INSIGHT_AGENT_CONCURRENCY", 8, 1, 64)
        self.probe_retries = env_int("INSIGHT_AGENT_PROBE_RETRIES", 1, 0, 5)
        self.probe_retry_delay_ms = env_int("INSIGHT_AGENT_PROBE_RETRY_DELAY_MS", 500, 0, 10000)
        self.batch_size = env_int("INSIGHT_AGENT_BATCH_SIZE", 200, 1, 1000)
        self.config_refresh = env_int("INSIGHT_AGENT_CONFIG_REFRESH_SEC", 60, 10, 3600)
        self.heartbeat_interval = env_int("INSIGHT_AGENT_HEARTBEAT_SEC", 30, 10, 3600)
        self.loop_sleep = env_int("INSIGHT_AGENT_LOOP_SLEEP_SEC", 2, 1, 30)
        self.blackbox_url = (os.getenv("INSIGHT_AGENT_BLACKBOX_URL") or "").strip()
        self.blackbox_fallback = env_bool("INSIGHT_AGENT_BLACKBOX_FALLBACK_NATIVE", True)
        spool_path = Path(os.getenv("INSIGHT_AGENT_SPOOL_PATH") or "/var/lib/insight-agent/spool.sqlite")
        spool_limit = env_int("INSIGHT_AGENT_SPOOL_MAX_SAMPLES", 100000, 1000, 10000000)
        self.spool = Spool(spool_path, spool_limit)
        self.client = HubClient(
            self.endpoint,
            self.node_key,
            self.secret,
            env_int("INSIGHT_AGENT_HTTP_TIMEOUT_SEC", 15, 3, 120),
            env_bool("INSIGHT_AGENT_VERIFY_TLS", True),
        )
        self.connectivity_status = "unknown"
        self.targets: list[dict[str, Any]] = []
        self.next_config_at = 0.0
        self.next_heartbeat_at = 0.0
        self.load_cached_config()

    def node_payload(self) -> dict[str, Any]:
        probe_types = ["http", "icmp", "tcp", "dns", "grpc"] if self.blackbox_url else ["http", "icmp", "tcp"]
        return {
            "capabilities": {
                "adapters": ["native", "blackbox"] if self.blackbox_url else ["native"],
                "probe_types": probe_types,
                "spool": "sqlite",
            },
            "connectivity_status": self.connectivity_status,
            "display_name": self.display_name,
            "region": self.region or None,
            "version": VERSION,
            "zone": self.zone or None,
        }

    def load_cached_config(self) -> None:
        raw = self.spool.get_state("config")
        if not raw:
            return
        try:
            config = json.loads(raw)
        except json.JSONDecodeError:
            return
        self.apply_config(config)

    def apply_config(self, config: Any) -> None:
        if not isinstance(config, dict) or not isinstance(config.get("targets"), list):
            raise RuntimeError("Configuration distribuée invalide.")
        targets = [target for target in config["targets"] if isinstance(target, dict)]
        self.targets = targets
        self.batch_size = max(1, min(1000, int(config.get("batch_size") or self.batch_size)))
        self.config_refresh = max(10, min(3600, int(config.get("refresh_after_sec") or self.config_refresh)))
        self.spool.sync_schedule({int(target.get("site_id") or 0) for target in targets if int(target.get("site_id") or 0) > 0})
        self.spool.set_state("config", compact_json(config))

    def fetch_config(self) -> None:
        response = self.client.post_payload(
            {
                "action": "config",
                "node": self.node_payload(),
                "sent_at_ms": int(time.time() * 1000),
            }
        )
        self.apply_config(response.get("config"))
        self.next_config_at = time.time() + self.config_refresh
        log(f"Configuration reçue: {len(self.targets)} cible(s), lot de {self.batch_size}.")

    def send_heartbeat(self) -> None:
        stats = self.spool.stats()
        payload = self.node_payload()
        payload["capabilities"]["spool_samples"] = stats["samples"]
        payload["capabilities"]["spool_batches"] = stats["batches"]
        self.client.post_payload(
            {
                "action": "heartbeat",
                "node": payload,
                "sent_at_ms": int(time.time() * 1000),
            }
        )
        self.next_heartbeat_at = time.time() + self.heartbeat_interval

    def check_connectivity(self) -> str:
        if not self.connectivity_target:
            return "unknown"
        target = self.connectivity_target
        if "://" in target:
            parsed = urlsplit(target)
            host = parsed.hostname or ""
            port = parsed.port or (443 if parsed.scheme == "https" else 80)
        elif target.startswith("[") and "]:" in target:
            host, port_raw = target[1:].split("]:", 1)
            port = int(port_raw)
        elif ":" in target:
            host, port_raw = target.rsplit(":", 1)
            port = int(port_raw)
        else:
            host, port = target, 443
        try:
            with socket.create_connection((host, port), timeout=self.connectivity_timeout):
                return "online"
        except (OSError, ValueError):
            return "offline"

    def blackbox_probe(self, target: dict[str, Any]) -> dict[str, Any]:
        probe_type = str(target.get("probe_type") or "http").lower()
        module_by_type = {
            "dns": os.getenv("INSIGHT_AGENT_BLACKBOX_DNS_MODULE") or "dns",
            "grpc": os.getenv("INSIGHT_AGENT_BLACKBOX_GRPC_MODULE") or "grpc",
            "http": os.getenv("INSIGHT_AGENT_BLACKBOX_HTTP_MODULE") or "http_2xx",
            "icmp": os.getenv("INSIGHT_AGENT_BLACKBOX_ICMP_MODULE") or "icmp",
            "ping": os.getenv("INSIGHT_AGENT_BLACKBOX_ICMP_MODULE") or "icmp",
            "tcp": os.getenv("INSIGHT_AGENT_BLACKBOX_TCP_MODULE") or "tcp_connect",
        }
        if probe_type not in module_by_type:
            raise RuntimeError(f"Type de sonde Blackbox non pris en charge: {probe_type}.")
        module = module_by_type[probe_type]
        probe_target = str(target.get("url") or "")
        if probe_type in {"icmp", "ping"}:
            parsed_target = urlsplit(probe_target if "://" in probe_target else "//" + probe_target)
            probe_target = parsed_target.hostname or probe_target
        parsed = urlsplit(self.blackbox_url)
        path = parsed.path.rstrip("/")
        if not path.endswith("/probe"):
            path = f"{path}/probe" if path else "/probe"
        query = dict(parse_qsl(parsed.query, keep_blank_values=True))
        query.update({"module": module, "target": probe_target})
        probe_url = urlunsplit((parsed.scheme, parsed.netloc, path, urlencode(query), ""))
        request = Request(probe_url, headers={"User-Agent": f"Insight-Agent/{VERSION}"})
        context = ssl.create_default_context() if env_bool("INSIGHT_AGENT_VERIFY_TLS", True) else ssl._create_unverified_context()
        with urlopen(request, timeout=env_int("INSIGHT_AGENT_PROBE_TIMEOUT_SEC", 15, 1, 120), context=context) as response:
            metrics = response.read().decode("utf-8", errors="replace")

        def metric(name: str) -> float | None:
            match = re.search(rf"^{re.escape(name)}(?:\{{[^}}]*\}})?\s+([-+0-9.eE]+)$", metrics, re.MULTILINE)
            return float(match.group(1)) if match else None

        success = metric("probe_success")
        duration = metric("probe_duration_seconds")
        http_code = metric("probe_http_status_code")
        if success is None:
            raise RuntimeError("Blackbox Exporter n’a pas renvoyé probe_success.")
        return {
            "http_code": int(http_code) if http_code is not None else None,
            "metadata": {"adapter": "blackbox", "module": module},
            "response_time_ms": round(duration * 1000, 3) if duration is not None else None,
            "status": "online" if success >= 1 else "offline",
        }

    def native_probe(self, target: dict[str, Any]) -> dict[str, Any]:
        method = str(target.get("http_primary_method") or "GET").upper()
        redirect = str(target.get("http_primary_redirect") or "follow").lower()
        config = {
            "http_methods": method,
            "http_primary_method": method,
            "http_primary_redirect": redirect,
            "http_redirect_modes": redirect,
        }
        probe_type = str(target.get("probe_type") or "http").lower()
        if probe_type not in {"http", "icmp", "ping", "tcp"}:
            raise RuntimeError(f"La sonde {probe_type} nécessite Blackbox Exporter.")
        manual = run_manual_check(str(target.get("url") or ""), probe_type, config)
        if not manual.get("ok"):
            raise RuntimeError(str(manual.get("message") or "Sonde native invalide."))
        result = manual.get("result") if isinstance(manual.get("result"), dict) else {}
        return {
            "http_code": result.get("http_code"),
            "metadata": {
                "adapter": "native",
                "follow_redirects": result.get("follow_redirects"),
                "http_method": result.get("http_method"),
            },
            "response_time_ms": result.get("response_time"),
            "status": str(result.get("status") or "unknown"),
        }

    def probe_once(self, target: dict[str, Any]) -> dict[str, Any]:
        if not self.blackbox_url:
            return self.native_probe(target)
        try:
            return self.blackbox_probe(target)
        except Exception as blackbox_error:
            probe_type = str(target.get("probe_type") or "http").lower()
            if not self.blackbox_fallback or probe_type not in {"http", "icmp", "ping", "tcp"}:
                raise
            result = self.native_probe(target)
            result["metadata"]["blackbox_error"] = str(blackbox_error)[:255]
            return result

    def probe(self, target: dict[str, Any]) -> dict[str, Any]:
        observed_at = now_iso()
        error_message = None
        result = {
            "http_code": None,
            "metadata": {"adapter": "error"},
            "response_time_ms": None,
            "status": "offline",
        }
        attempts = 0
        for attempt in range(self.probe_retries + 1):
            attempts = attempt + 1
            try:
                result = self.probe_once(target)
                error_message = None
            except Exception as error:
                error_message = str(error)[:255]
                result = {
                    "http_code": None,
                    "metadata": {"adapter": "error"},
                    "response_time_ms": None,
                    "status": "offline",
                }
            if str(result.get("status") or "unknown") in {"online", "degraded"}:
                break
            if attempt < self.probe_retries and self.probe_retry_delay_ms > 0:
                time.sleep(self.probe_retry_delay_ms / 1000)
        result["metadata"]["attempts"] = attempts
        return {
            "error_code": "probe_error" if error_message else None,
            "error_message": error_message,
            "http_code": result.get("http_code"),
            "metadata": result.get("metadata") or {},
            "observed_at": observed_at,
            "response_time_ms": result.get("response_time_ms"),
            "sample_id": uuid.uuid4().hex,
            "site_id": int(target.get("site_id") or 0),
            "status": str(result.get("status") or "unknown"),
        }

    def run_due_probes(self, force: bool = False) -> int:
        current_time = time.time()
        due = [
            target
            for target in self.targets
            if int(target.get("site_id") or 0) > 0
            and (force or self.spool.is_due(int(target["site_id"]), current_time))
        ]
        if not due:
            return 0
        if self.connectivity_status == "offline":
            for target in due:
                interval = max(10, int(target.get("interval_sec") or 60))
                self.spool.schedule(int(target["site_id"]), current_time + min(30, interval))
            log(f"Connectivité locale indisponible: {len(due)} sonde(s) différée(s).", "warning")
            return 0
        completed = 0
        workers = min(self.concurrency, len(due))
        with ThreadPoolExecutor(max_workers=workers) as executor:
            futures = {executor.submit(self.probe, target): target for target in due}
            for future in as_completed(futures):
                target = futures[future]
                site_id = int(target["site_id"])
                interval = max(10, int(target.get("interval_sec") or 60))
                observation = future.result()
                dropped = self.spool.enqueue(observation)
                self.spool.schedule(site_id, time.time() + interval)
                completed += 1
                if dropped:
                    log(f"Spool saturé: {dropped} ancienne(s) mesure(s) supprimée(s).", "warning")
        log(f"{completed} sonde(s) exécutée(s) depuis {self.node_key}.")
        return completed

    def flush(self, maximum_batches: int = 10) -> int:
        sent = 0
        for _ in range(maximum_batches):
            self.spool.prepare_batch(self.node_key, self.node_payload(), self.batch_size)
            row = self.spool.next_batch(time.time())
            if row is None:
                break
            batch_id = str(row["batch_id"])
            attempts = int(row["attempts"]) + 1
            try:
                response = self.client.post_raw(str(row["payload_json"]))
                result = response.get("result") if isinstance(response.get("result"), dict) else {}
                rejected = int(result.get("rejected") or 0)
                self.spool.complete_batch(batch_id)
                sent += 1
                if rejected:
                    log(f"Lot {batch_id[:12]} accepté avec {rejected} rejet(s).", "warning")
            except AgentHttpError as error:
                delay = self.spool.retry_batch(batch_id, attempts)
                log(
                    f"Envoi du lot {batch_id[:12]} impossible ({error.status or 'réseau'}): {error}. Nouvelle tentative dans {delay:.1f} s.",
                    "warning",
                )
                break
        return sent

    def run(self) -> None:
        log(f"Insight Agent {VERSION} démarre sur le nœud {self.node_key}.")
        while True:
            current_time = time.time()
            previous_connectivity = self.connectivity_status
            self.connectivity_status = self.check_connectivity()
            if self.connectivity_status != previous_connectivity:
                log(f"Connectivité locale: {self.connectivity_status}.")
            if self.once or current_time >= self.next_config_at:
                try:
                    self.fetch_config()
                except AgentHttpError as error:
                    self.next_config_at = time.time() + min(60, self.config_refresh)
                    log(f"Configuration du hub indisponible ({error.status or 'réseau'}): {error}.", "warning")
            if self.once or current_time >= self.next_heartbeat_at:
                try:
                    self.send_heartbeat()
                except AgentHttpError as error:
                    self.next_heartbeat_at = time.time() + min(30, self.heartbeat_interval)
                    log(f"Heartbeat indisponible ({error.status or 'réseau'}): {error}.", "warning")
            self.flush()
            self.run_due_probes(force=self.once)
            self.flush()
            if self.once:
                stats = self.spool.stats()
                log(f"Exécution unique terminée: {stats['samples']} mesure(s) et {stats['batches']} lot(s) en attente.")
                return
            time.sleep(self.loop_sleep)


def main() -> int:
    parser = argparse.ArgumentParser(description="Agent de monitoring distribué Insight")
    parser.add_argument("--once", action="store_true", help="Exécute un cycle puis s’arrête")
    parser.add_argument("--version", action="version", version=f"Insight Agent {VERSION}")
    arguments = parser.parse_args()
    agent = None
    try:
        agent = InsightAgent(once=arguments.once)
        agent.run()
        return 0
    except KeyboardInterrupt:
        log("Arrêt demandé.")
        return 0
    except Exception as error:
        log(str(error), "error")
        return 1
    finally:
        if agent is not None:
            agent.spool.close()


if __name__ == "__main__":
    raise SystemExit(main())
