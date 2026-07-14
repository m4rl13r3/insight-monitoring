from __future__ import annotations

import json
import os
import re
import shutil
import socket
import smtplib
import ssl
import subprocess
import threading
import time
from datetime import datetime, timezone
from pathlib import Path
from typing import Any
from urllib.parse import unquote, urlparse

from .notifications import decrypt_config


def load_probe_config(ciphertext: Any) -> dict[str, Any]:
    if isinstance(ciphertext, dict):
        return ciphertext
    raw = str(ciphertext or "").strip()
    if not raw:
        return {}
    try:
        decoded = decrypt_config(raw)
    except Exception:
        return {}
    return decoded if isinstance(decoded, dict) else {}


def _elapsed_ms(started: float) -> float:
    return round((time.perf_counter() - started) * 1000, 2)


def _bounded_timeout(value: Any, default: int = 10) -> int:
    try:
        parsed = int(str(value).strip())
    except Exception:
        parsed = default
    return max(1, min(120, parsed))


def _substitute(value: Any, variables: dict[str, Any]) -> str:
    text = str(value or "")
    return re.sub(r"\$\{([A-Z][A-Z0-9_]{0,63})\}", lambda match: str(variables.get(match.group(1), "")), text)


def _artifact_target(site_id: int) -> tuple[Path, str]:
    root = Path(os.getenv("INSIGHT_DATA_DIR", "/var/lib/insight")).resolve()
    stamp = datetime.now(timezone.utc).strftime("%Y%m%dT%H%M%S%fZ")
    relative = Path("diagnostics") / str(max(0, site_id)) / f"{stamp}.png"
    target = root / relative
    target.parent.mkdir(parents=True, exist_ok=True)
    return target, relative.as_posix()


def check_browser_status(url: str, cfg: dict[str, Any]) -> dict[str, Any]:
    started = time.perf_counter()
    timeout_ms = _bounded_timeout(cfg.get("timeout_sec"), 20) * 1000
    site_id = int(cfg.get("site_id") or 0)
    options = load_probe_config(cfg.get("probe_config_ciphertext"))
    variables = options.get("variables") if isinstance(options.get("variables"), dict) else {}
    raw_scenario = str(cfg.get("browser_script") or "").strip()
    try:
        scenario = json.loads(raw_scenario) if raw_scenario else []
    except json.JSONDecodeError:
        return {"status": "offline", "response_time": _elapsed_ms(started), "http_code": None, "error": "browser_scenario_invalid"}
    if not isinstance(scenario, list) or len(scenario) > 50 or any(not isinstance(step, dict) for step in scenario):
        return {"status": "offline", "response_time": _elapsed_ms(started), "http_code": None, "error": "browser_scenario_invalid"}
    console_entries: list[str] = []
    artifact_path = ""
    http_code: int | None = None
    browser = None
    try:
        from playwright.sync_api import sync_playwright

        with sync_playwright() as playwright:
            browser = playwright.chromium.launch(headless=True)
            context = browser.new_context(ignore_https_errors=not bool(int(cfg.get("tls_verify") if cfg.get("tls_verify") is not None else 1)))
            page = context.new_page()
            page.on("console", lambda message: console_entries.append(f"console:{message.type}:{message.text}"[:1000]))
            page.on("pageerror", lambda error: console_entries.append(f"pageerror:{error}"[:1000]))
            has_navigation = any(str(step.get("action") or "").strip().lower() == "goto" for step in scenario)
            if not has_navigation:
                response = page.goto(url, wait_until="domcontentloaded", timeout=timeout_ms)
                http_code = int(response.status) if response is not None else None
            for step in scenario:
                action = str(step.get("action") or "").strip().lower()
                selector = _substitute(step.get("selector"), variables)
                value = _substitute(step.get("value"), variables)
                if action == "goto":
                    response = page.goto(_substitute(step.get("url") or url, variables), wait_until="domcontentloaded", timeout=timeout_ms)
                    http_code = int(response.status) if response is not None else None
                elif action == "click" and selector:
                    page.locator(selector).click(timeout=timeout_ms)
                elif action == "fill" and selector:
                    page.locator(selector).fill(value, timeout=timeout_ms)
                elif action == "press" and selector:
                    page.locator(selector).press(value, timeout=timeout_ms)
                elif action == "wait_for" and selector:
                    page.locator(selector).wait_for(state=str(step.get("state") or "visible"), timeout=timeout_ms)
                elif action == "expect_text":
                    content = page.locator(selector or "body").inner_text(timeout=timeout_ms)
                    if value not in content:
                        raise RuntimeError("browser_text_mismatch")
                elif action == "expect_url":
                    if re.search(value, page.url) is None:
                        raise RuntimeError("browser_url_mismatch")
                elif action == "evaluate" and value:
                    page.evaluate(value)
                elif action not in {"", "screenshot"}:
                    raise RuntimeError("browser_action_invalid")
            if bool(options.get("capture_success_screenshot")) or any(str(step.get("action") or "").lower() == "screenshot" for step in scenario):
                target, artifact_path = _artifact_target(site_id)
                page.screenshot(path=str(target), full_page=True)
            context.close()
            browser.close()
            browser = None
        return {
            "status": "online",
            "response_time": _elapsed_ms(started),
            "http_code": http_code,
            "error": "",
            "diagnostic": {"timing": {"total_ms": _elapsed_ms(started)}, "browser_logs": console_entries[-50:], "artifact_path": artifact_path},
        }
    except Exception as exc:
        try:
            if browser is not None:
                browser.close()
        except Exception:
            pass
        error = str(exc or "browser_failed")[:500]
        try:
            target, artifact_path = _artifact_target(site_id)
            if "page" in locals():
                page.screenshot(path=str(target), full_page=True)
        except Exception:
            artifact_path = ""
        return {
            "status": "offline",
            "response_time": _elapsed_ms(started),
            "http_code": http_code,
            "error": error,
            "diagnostic": {"timing": {"total_ms": _elapsed_ms(started)}, "browser_logs": console_entries[-50:], "artifact_path": artifact_path},
        }


def check_websocket_status(url: str, cfg: dict[str, Any]) -> dict[str, Any]:
    started = time.perf_counter()
    options = load_probe_config(cfg.get("probe_config_ciphertext"))
    connection = None
    try:
        import websocket

        connection = websocket.create_connection(
            url,
            timeout=_bounded_timeout(cfg.get("timeout_sec")),
            header=[f"{key}: {value}" for key, value in (options.get("headers") or {}).items()],
            sslopt={"cert_reqs": 2 if bool(int(cfg.get("tls_verify") if cfg.get("tls_verify") is not None else 1)) else 0},
        )
        outgoing = str(options.get("send") or "")
        expected = str(options.get("expect") or "")
        received = ""
        if outgoing:
            connection.send(outgoing)
        if expected:
            received = str(connection.recv() or "")[:10000]
            if expected not in received:
                raise RuntimeError("websocket_value_mismatch")
        return {"status": "online", "response_time": _elapsed_ms(started), "http_code": 101, "error": "", "diagnostic": {"timing": {"total_ms": _elapsed_ms(started)}, "message_excerpt": received[:500]}}
    except Exception as exc:
        return {"status": "offline", "response_time": _elapsed_ms(started), "http_code": None, "error": str(exc or "websocket_failed")[:500]}
    finally:
        try:
            if connection is not None:
                connection.close()
        except Exception:
            pass


def check_mqtt_status(url: str, cfg: dict[str, Any]) -> dict[str, Any]:
    started = time.perf_counter()
    parsed = urlparse(url)
    topic = unquote(parsed.path.lstrip("/"))
    options = load_probe_config(cfg.get("probe_config_ciphertext"))
    event = threading.Event()
    state: dict[str, Any] = {"connected": False, "payload": "", "error": ""}
    client = None
    try:
        import paho.mqtt.client as mqtt

        client = mqtt.Client(mqtt.CallbackAPIVersion.VERSION2)
        username = str(options.get("username") or "")
        password = str(options.get("password") or "")
        if username:
            client.username_pw_set(username, password)
        if parsed.scheme == "mqtts":
            client.tls_set()
            client.tls_insecure_set(not bool(int(cfg.get("tls_verify") if cfg.get("tls_verify") is not None else 1)))

        def on_connect(active_client: Any, _userdata: Any, _flags: Any, reason_code: Any, _properties: Any) -> None:
            state["connected"] = int(reason_code) == 0
            if not state["connected"]:
                state["error"] = f"mqtt_connect_{reason_code}"
                event.set()
            elif topic:
                active_client.subscribe(topic, qos=max(0, min(2, int(options.get("qos") or 0))))
            else:
                event.set()

        def on_message(_client: Any, _userdata: Any, message: Any) -> None:
            state["payload"] = bytes(message.payload).decode("utf-8", errors="replace")[:10000]
            event.set()

        client.on_connect = on_connect
        client.on_message = on_message
        client.connect(parsed.hostname or "", parsed.port or (8883 if parsed.scheme == "mqtts" else 1883), keepalive=30)
        client.loop_start()
        event.wait(_bounded_timeout(cfg.get("timeout_sec")))
        expected = str(options.get("expect") or "")
        if not state["connected"]:
            raise RuntimeError(str(state["error"] or "mqtt_timeout"))
        if topic and not event.is_set():
            raise RuntimeError("mqtt_message_timeout")
        if expected and expected not in str(state["payload"]):
            raise RuntimeError("mqtt_value_mismatch")
        return {"status": "online", "response_time": _elapsed_ms(started), "http_code": None, "error": "", "diagnostic": {"timing": {"total_ms": _elapsed_ms(started)}, "message_excerpt": str(state["payload"])[:500]}}
    except Exception as exc:
        return {"status": "offline", "response_time": _elapsed_ms(started), "http_code": None, "error": str(exc or "mqtt_failed")[:500]}
    finally:
        try:
            if client is not None:
                client.loop_stop()
                client.disconnect()
        except Exception:
            pass


def _read_only_query(query: str) -> str:
    normalized = str(query or "").strip()
    without_final = normalized[:-1].rstrip() if normalized.endswith(";") else normalized
    if not without_final or ";" in without_final or re.match(r"^(SELECT|SHOW|WITH|EXPLAIN)\b", without_final, re.IGNORECASE) is None:
        raise ValueError("sql_read_only_required")
    return without_final[:20000]


def check_sql_status(url: str, cfg: dict[str, Any]) -> dict[str, Any]:
    started = time.perf_counter()
    parsed = urlparse(url)
    options = load_probe_config(cfg.get("probe_config_ciphertext"))
    query = ""
    connection = None
    try:
        query = _read_only_query(str(options.get("query") or "SELECT 1"))
        username = str(options.get("username") or "")
        password = str(options.get("password") or "")
        database = unquote(parsed.path.lstrip("/"))
        timeout = _bounded_timeout(cfg.get("timeout_sec"))
        if parsed.scheme in {"mysql", "mariadb"}:
            import pymysql

            connection = pymysql.connect(host=parsed.hostname or "", port=parsed.port or 3306, user=username, password=password, database=database, connect_timeout=timeout, read_timeout=timeout, write_timeout=timeout, autocommit=True)
        elif parsed.scheme in {"postgres", "postgresql"}:
            import psycopg

            connection = psycopg.connect(host=parsed.hostname or "", port=parsed.port or 5432, user=username, password=password, dbname=database, connect_timeout=timeout, autocommit=True)
        else:
            raise RuntimeError("sql_driver_unsupported")
        with connection.cursor() as cursor:
            cursor.execute(query)
            row = cursor.fetchone()
        value = "" if not row else str(row[0])
        expected = str(options.get("expect") or "")
        if expected and value != expected:
            raise RuntimeError("sql_value_mismatch")
        return {"status": "online", "response_time": _elapsed_ms(started), "http_code": None, "error": "", "diagnostic": {"timing": {"total_ms": _elapsed_ms(started)}, "result": value[:500]}}
    except Exception as exc:
        return {"status": "offline", "response_time": _elapsed_ms(started), "http_code": None, "error": str(exc or "sql_failed")[:500]}
    finally:
        try:
            if connection is not None:
                connection.close()
        except Exception:
            pass


def check_docker_status(url: str, cfg: dict[str, Any]) -> dict[str, Any]:
    started = time.perf_counter()
    parsed = urlparse(url)
    container_name = unquote(parsed.path.lstrip("/"))
    client = None
    try:
        import docker

        host = parsed.hostname or ""
        if host in {"local", "socket"}:
            if str(os.getenv("INSIGHT_DOCKER_SOCKET_ENABLED", "0")).strip().lower() not in {"1", "true", "yes", "on"}:
                raise RuntimeError("docker_socket_disabled")
            socket_path = str(os.getenv("INSIGHT_DOCKER_SOCKET_PATH", "/var/run/docker.sock"))
            client = docker.DockerClient(base_url=f"unix://{socket_path}", timeout=_bounded_timeout(cfg.get("timeout_sec")))
        else:
            client = docker.DockerClient(base_url=f"tcp://{host}:{parsed.port or 2376}", timeout=_bounded_timeout(cfg.get("timeout_sec")), tls=bool(int(cfg.get("tls_verify") if cfg.get("tls_verify") is not None else 1)))
        container = client.containers.get(container_name)
        container.reload()
        status = str(container.status or "unknown").lower()
        health = str(((container.attrs.get("State") or {}).get("Health") or {}).get("Status") or "")
        effective = "online" if status == "running" and health not in {"unhealthy"} else "degraded" if status == "running" else "offline"
        return {"status": effective, "response_time": _elapsed_ms(started), "http_code": None, "error": "" if effective == "online" else f"docker_{health or status}", "diagnostic": {"timing": {"total_ms": _elapsed_ms(started)}, "container_status": status, "health": health}}
    except Exception as exc:
        return {"status": "offline", "response_time": _elapsed_ms(started), "http_code": None, "error": str(exc or "docker_failed")[:500]}
    finally:
        try:
            if client is not None:
                client.close()
        except Exception:
            pass


def check_grpc_status(url: str, cfg: dict[str, Any]) -> dict[str, Any]:
    started = time.perf_counter()
    parsed = urlparse(url)
    options = load_probe_config(cfg.get("probe_config_ciphertext"))
    channel = None
    try:
        import grpc
        from grpc_health.v1 import health_pb2, health_pb2_grpc

        host = parsed.hostname or ""
        port = parsed.port or (443 if parsed.scheme == "grpcs" else 50051)
        target = f"{host}:{port}"
        timeout = _bounded_timeout(cfg.get("timeout_sec"))
        if parsed.scheme == "grpcs":
            credentials = grpc.ssl_channel_credentials()
            channel = grpc.secure_channel(target, credentials)
        else:
            channel = grpc.insecure_channel(target)
        response = health_pb2_grpc.HealthStub(channel).Check(
            health_pb2.HealthCheckRequest(service=str(options.get("service") or "")),
            timeout=timeout,
        )
        if response.status != health_pb2.HealthCheckResponse.SERVING:
            raise RuntimeError("grpc_not_serving")
        return {"status": "online", "response_time": _elapsed_ms(started), "http_code": None, "error": "", "diagnostic": {"timing": {"total_ms": _elapsed_ms(started)}, "service": str(options.get("service") or "")}}
    except Exception as exc:
        return {"status": "offline", "response_time": _elapsed_ms(started), "http_code": None, "error": str(exc or "grpc_failed")[:500]}
    finally:
        if channel is not None:
            channel.close()


def check_redis_status(url: str, cfg: dict[str, Any]) -> dict[str, Any]:
    started = time.perf_counter()
    parsed = urlparse(url)
    options = load_probe_config(cfg.get("probe_config_ciphertext"))
    client = None
    try:
        import redis

        database = 0
        path = parsed.path.strip("/")
        if path:
            database = max(0, min(15, int(path)))
        client = redis.Redis(
            host=parsed.hostname or "",
            port=parsed.port or (6380 if parsed.scheme == "rediss" else 6379),
            db=database,
            username=str(options.get("username") or "") or None,
            password=str(options.get("password") or "") or None,
            ssl=parsed.scheme == "rediss",
            ssl_cert_reqs="required" if bool(int(cfg.get("tls_verify") if cfg.get("tls_verify") is not None else 1)) else "none",
            socket_connect_timeout=_bounded_timeout(cfg.get("timeout_sec")),
            socket_timeout=_bounded_timeout(cfg.get("timeout_sec")),
        )
        if not bool(client.ping()):
            raise RuntimeError("redis_ping_failed")
        return {"status": "online", "response_time": _elapsed_ms(started), "http_code": None, "error": "", "diagnostic": {"timing": {"total_ms": _elapsed_ms(started)}, "database": database}}
    except Exception as exc:
        return {"status": "offline", "response_time": _elapsed_ms(started), "http_code": None, "error": str(exc or "redis_failed")[:500]}
    finally:
        if client is not None:
            try:
                client.close()
            except Exception:
                pass


def check_smtp_status(url: str, cfg: dict[str, Any]) -> dict[str, Any]:
    started = time.perf_counter()
    parsed = urlparse(url)
    options = load_probe_config(cfg.get("probe_config_ciphertext"))
    connection = None
    try:
        timeout = _bounded_timeout(cfg.get("timeout_sec"))
        encryption = str(options.get("encryption") or ("ssl" if parsed.scheme == "smtps" else "starttls")).lower()
        host = parsed.hostname or ""
        port = parsed.port or (465 if encryption == "ssl" else 587)
        context = ssl.create_default_context()
        if not bool(int(cfg.get("tls_verify") if cfg.get("tls_verify") is not None else 1)):
            context.check_hostname = False
            context.verify_mode = ssl.CERT_NONE
        if encryption == "ssl":
            connection = smtplib.SMTP_SSL(host, port, timeout=timeout, context=context)
        else:
            connection = smtplib.SMTP(host, port, timeout=timeout)
            connection.ehlo_or_helo_if_needed()
            if encryption == "starttls":
                connection.starttls(context=context)
                connection.ehlo_or_helo_if_needed()
        username = str(options.get("username") or "")
        if username:
            connection.login(username, str(options.get("password") or ""))
        connection.noop()
        return {"status": "online", "response_time": _elapsed_ms(started), "http_code": None, "error": "", "diagnostic": {"timing": {"total_ms": _elapsed_ms(started)}, "encryption": encryption}}
    except Exception as exc:
        return {"status": "offline", "response_time": _elapsed_ms(started), "http_code": None, "error": str(exc or "smtp_failed")[:500]}
    finally:
        if connection is not None:
            try:
                connection.quit()
            except Exception:
                connection.close()


def check_rabbitmq_status(url: str, cfg: dict[str, Any]) -> dict[str, Any]:
    started = time.perf_counter()
    parsed = urlparse(url)
    options = load_probe_config(cfg.get("probe_config_ciphertext"))
    connection = None
    try:
        import pika

        timeout = _bounded_timeout(cfg.get("timeout_sec"))
        credentials = pika.PlainCredentials(str(options.get("username") or "guest"), str(options.get("password") or "guest"))
        context = None
        if parsed.scheme == "amqps":
            context = ssl.create_default_context()
            if not bool(int(cfg.get("tls_verify") if cfg.get("tls_verify") is not None else 1)):
                context.check_hostname = False
                context.verify_mode = ssl.CERT_NONE
        parameters = pika.ConnectionParameters(
            host=parsed.hostname or "",
            port=parsed.port or (5671 if parsed.scheme == "amqps" else 5672),
            virtual_host=unquote(parsed.path or "/") or "/",
            credentials=credentials,
            socket_timeout=timeout,
            blocked_connection_timeout=timeout,
            connection_attempts=1,
            retry_delay=0,
            ssl_options=pika.SSLOptions(context, parsed.hostname) if context is not None else None,
        )
        connection = pika.BlockingConnection(parameters)
        channel = connection.channel()
        channel.close()
        return {"status": "online", "response_time": _elapsed_ms(started), "http_code": None, "error": "", "diagnostic": {"timing": {"total_ms": _elapsed_ms(started)}, "virtual_host": unquote(parsed.path or "/") or "/"}}
    except Exception as exc:
        return {"status": "offline", "response_time": _elapsed_ms(started), "http_code": None, "error": str(exc or "rabbitmq_failed")[:500]}
    finally:
        if connection is not None and connection.is_open:
            connection.close()


def check_snmp_status(url: str, cfg: dict[str, Any]) -> dict[str, Any]:
    started = time.perf_counter()
    parsed = urlparse(url)
    options = load_probe_config(cfg.get("probe_config_ciphertext"))
    try:
        community = str(options.get("community") or "public")
        oid = str(options.get("oid") or "1.3.6.1.2.1.1.3.0")
        if re.fullmatch(r"[0-9]+(?:\.[0-9]+)+", oid) is None:
            raise RuntimeError("snmp_oid_invalid")
        host = parsed.hostname or ""
        port = parsed.port or 161
        timeout = _bounded_timeout(cfg.get("timeout_sec"))
        completed = subprocess.run(
            ["snmpget", "-v2c", "-c", community, "-t", str(timeout), "-r", "0", f"{host}:{port}", oid],
            capture_output=True,
            text=True,
            timeout=timeout + 2,
            check=False,
        )
        if completed.returncode != 0:
            raise RuntimeError((completed.stderr or completed.stdout or "snmp_get_failed").strip()[:500])
        output = (completed.stdout or "").strip()
        expected = str(options.get("expect") or "")
        if expected and expected not in output:
            raise RuntimeError("snmp_value_mismatch")
        return {"status": "online", "response_time": _elapsed_ms(started), "http_code": None, "error": "", "diagnostic": {"timing": {"total_ms": _elapsed_ms(started)}, "oid": oid, "value": output[:500]}}
    except Exception as exc:
        return {"status": "offline", "response_time": _elapsed_ms(started), "http_code": None, "error": str(exc or "snmp_failed")[:500]}


def collect_network_diagnostics(host: str, timeout_sec: int = 5) -> dict[str, Any]:
    result: dict[str, Any] = {"host": host, "addresses": [], "tool": "none", "output": ""}
    started = time.perf_counter()
    try:
        result["addresses"] = sorted({entry[4][0] for entry in socket.getaddrinfo(host, None)})[:20]
    except Exception as exc:
        result["dns_error"] = str(exc)[:500]
    result["dns_ms"] = _elapsed_ms(started)
    command: list[str] = []
    if shutil.which("mtr"):
        command = ["mtr", "--report", "--report-cycles", "3", "--json", "--no-dns", host]
        result["tool"] = "mtr"
    elif shutil.which("traceroute"):
        command = ["traceroute", "-n", "-m", "12", "-w", "1", host]
        result["tool"] = "traceroute"
    if command:
        try:
            completed = subprocess.run(command, capture_output=True, text=True, timeout=max(3, min(30, timeout_sec * 3)), check=False)
            result["output"] = (completed.stdout or completed.stderr or "")[:20000]
            result["exit_code"] = int(completed.returncode)
        except Exception as exc:
            result["error"] = str(exc)[:500]
    return result
