from __future__ import annotations

import argparse
import json
import os
import sys
from pathlib import Path
from typing import Any, Dict

if __package__ in (None, ""):
    sys.path.insert(0, str(Path(__file__).resolve().parent.parent))

from python_monitoring.actions import add_site, delete_site, list_sites, update_site
from python_monitoring.config import load_monitoring_config
from python_monitoring.declarative import apply_configuration, export_configuration, load_configuration, render_configuration
from python_monitoring.daily import run_daily_job
from python_monitoring.db import Database, DbConfig
from python_monitoring.distributed import (
    DistributedError,
    derive_node_secret,
    list_nodes,
    process_agent_request,
    provision_node,
    run_consensus_job,
    set_node_status,
    summary,
    verify_signature,
)
from python_monitoring.hourly import run_hourly_job
from python_monitoring.monitor import run_manual_check, run_monitor_job
from python_monitoring.retention import run_retention_job
from python_monitoring.runtime_state import write_aggregation_state, write_monitor_state


def _monitoring_root(path_override: str | None) -> Path:
    if path_override:
        return Path(path_override).resolve()
    return Path(__file__).resolve().parent.parent


def _database_from_root(root: Path) -> Database:
    cfg = load_monitoring_config(root)
    return Database(
        DbConfig(
            host=cfg.get("servername", "localhost"),
            user=cfg.get("username", ""),
            password=cfg.get("password", ""),
            database=cfg.get("dbname", ""),
            port=int(cfg.get("port", "3306") or 3306),
            socket=cfg.get("db_socket", ""),
        )
    )


def _parse_args(argv: list[str]) -> argparse.Namespace:
    parser = argparse.ArgumentParser(description="Insight monitoring engine")
    parser.add_argument("--root", help="Monitoring root directory", default=None)

    subparsers = parser.add_subparsers(dest="command", required=True)

    action_parser = subparsers.add_parser("actions", help="Site CRUD actions")
    action_parser.add_argument("action", choices=["list", "add", "delete", "update"])
    action_parser.add_argument("--site-id", type=int, default=0)
    action_parser.add_argument("--site-url", default="")
    action_parser.add_argument("--probe-type", default="http")
    action_parser.add_argument("--interval-sec", default="60")
    action_parser.add_argument("--calc-method", default="inherit")
    action_parser.add_argument("--http-methods", default="")
    action_parser.add_argument("--http-redirect-modes", default="")
    action_parser.add_argument("--http-primary-method", default="")
    action_parser.add_argument("--http-primary-redirect", default="")
    action_parser.add_argument("--name", default="")
    action_parser.add_argument("--active", default="1")
    action_parser.add_argument("--timeout-sec", default="10")
    action_parser.add_argument("--retry-count", default="2")
    action_parser.add_argument("--failure-threshold", default="2")
    action_parser.add_argument("--recovery-threshold", default="2")
    action_parser.add_argument("--accepted-status-codes", default="200-399")
    action_parser.add_argument("--keyword-text", default="")
    action_parser.add_argument("--keyword-mode", default="none")
    action_parser.add_argument("--json-path", default="")
    action_parser.add_argument("--json-expected-value", default="")
    action_parser.add_argument("--request-headers-json", default="")
    action_parser.add_argument("--request-body", default="")
    action_parser.add_argument("--basic-auth-username", default="")
    action_parser.add_argument("--basic-auth-password-ciphertext", default="")
    action_parser.add_argument("--probe-config-ciphertext", default="")
    action_parser.add_argument("--browser-script", default="")
    action_parser.add_argument("--diagnostics-enabled", default="1")
    action_parser.add_argument("--diagnostic-capture-body", default="0")
    action_parser.add_argument("--tls-verify", default="1")
    action_parser.add_argument("--tls-expiry-threshold-days", default="14")
    action_parser.add_argument("--dns-record-type", default="A")
    action_parser.add_argument("--dns-expected-value", default="")
    action_parser.add_argument("--heartbeat-grace-sec", default="300")
    action_parser.add_argument("--slo-target-percent", default="99.9")
    action_parser.add_argument("--public-visible", default="1")

    subparsers.add_parser("hourly", help="Build hourly stats")
    subparsers.add_parser("daily", help="Build daily stats")
    subparsers.add_parser("retention", help="Remove expired monitoring data")
    subparsers.add_parser("monitor", help="Run monitoring checks")
    subparsers.add_parser("consensus", help="Evaluate distributed observations")
    subparsers.add_parser("distributed-summary", help="Read distributed monitoring state")

    config_parser = subparsers.add_parser("config", help="Export, validate, or apply declarative configuration")
    config_parser.add_argument("action", choices=["export", "validate", "apply"])
    config_parser.add_argument("--file", default="insight.yml")
    config_parser.add_argument("--format", choices=["yaml", "json"], default="yaml")
    config_parser.add_argument("--dry-run", action="store_true")
    config_parser.add_argument("--prune", action="store_true")

    agent_parser = subparsers.add_parser("agent-request", help="Process an authenticated agent request")
    agent_parser.add_argument("--node-key", required=True)
    agent_parser.add_argument("--timestamp", required=True)
    agent_parser.add_argument("--nonce", required=True)
    agent_parser.add_argument("--signature", required=True)
    agent_parser.add_argument("--remote-address", default="unknown")

    secret_parser = subparsers.add_parser("node-secret", help="Derive one agent secret")
    secret_parser.add_argument("--node-key", required=True)

    nodes_parser = subparsers.add_parser("nodes", help="Manage distributed nodes")
    nodes_parser.add_argument("action", choices=["list", "provision", "activate", "pause", "revoke"])
    nodes_parser.add_argument("--node-key", default="")
    nodes_parser.add_argument("--display-name", default="")
    nodes_parser.add_argument("--region", default="")
    nodes_parser.add_argument("--zone", default="")
    manual_check_parser = subparsers.add_parser("manual-check", help="Run one manual check without DB insert")
    manual_check_parser.add_argument("--site-url", default="")
    manual_check_parser.add_argument("--probe-type", default="http")
    manual_check_parser.add_argument("--http-methods", default="")
    manual_check_parser.add_argument("--http-redirect-modes", default="")
    manual_check_parser.add_argument("--http-primary-method", default="")
    manual_check_parser.add_argument("--http-primary-redirect", default="")
    return parser.parse_args(argv)


def _site_settings(args: argparse.Namespace) -> Dict[str, Any]:
    return {
        "name": args.name,
        "active": args.active,
        "timeout_sec": args.timeout_sec,
        "retry_count": args.retry_count,
        "failure_threshold": args.failure_threshold,
        "recovery_threshold": args.recovery_threshold,
        "accepted_status_codes": args.accepted_status_codes,
        "keyword_text": args.keyword_text,
        "keyword_mode": args.keyword_mode,
        "json_path": args.json_path,
        "json_expected_value": args.json_expected_value,
        "request_headers_json": args.request_headers_json,
        "request_body": args.request_body,
        "basic_auth_username": args.basic_auth_username,
        "basic_auth_password_ciphertext": args.basic_auth_password_ciphertext,
        "probe_config_ciphertext": args.probe_config_ciphertext,
        "browser_script": args.browser_script,
        "diagnostics_enabled": args.diagnostics_enabled,
        "diagnostic_capture_body": args.diagnostic_capture_body,
        "tls_verify": args.tls_verify,
        "tls_expiry_threshold_days": args.tls_expiry_threshold_days,
        "dns_record_type": args.dns_record_type,
        "dns_expected_value": args.dns_expected_value,
        "heartbeat_grace_sec": args.heartbeat_grace_sec,
        "slo_target_percent": args.slo_target_percent,
        "public_visible": args.public_visible,
    }


def _run_command(args: argparse.Namespace, root: Path, db: Database) -> Dict[str, Any]:
    if args.command == "actions":
        if args.action == "list":
            return list_sites(db)
        if args.action == "add":
            return add_site(
                db,
                args.site_url,
                args.probe_type,
                args.interval_sec,
                args.calc_method,
                args.http_methods,
                args.http_redirect_modes,
                args.http_primary_method,
                args.http_primary_redirect,
                _site_settings(args),
            )
        if args.action == "delete":
            return delete_site(db, int(args.site_id or 0))
        return update_site(
            db,
            int(args.site_id or 0),
            args.site_url,
            args.probe_type,
            args.interval_sec,
            args.calc_method,
            args.http_methods,
            args.http_redirect_modes,
            args.http_primary_method,
            args.http_primary_redirect,
            _site_settings(args),
        )

    if args.command == "hourly":
        cfg = load_monitoring_config(root)
        return run_hourly_job(db, cfg)

    if args.command == "daily":
        cfg = load_monitoring_config(root)
        return run_daily_job(db, cfg)

    if args.command == "retention":
        cfg = load_monitoring_config(root)
        return run_retention_job(db, cfg)

    if args.command == "monitor":
        cfg = load_monitoring_config(root)
        return run_monitor_job(db, cfg, root)

    if args.command == "consensus":
        cfg = load_monitoring_config(root)
        return run_consensus_job(db, cfg)

    if args.command == "distributed-summary":
        return {"ok": True, "mode": str(os.getenv("INSIGHT_DISTRIBUTED_MODE", "standalone")), "data": summary(db)}

    if args.command == "config":
        if args.action == "export":
            document = export_configuration(db)
            return {"ok": True, "document": document, "rendered": render_configuration(document, str(args.format))}
        document = load_configuration(str(args.file))
        if args.action == "validate":
            return {"ok": True, "version": 1, "monitors": len(document["monitors"]), "runbooks": len(document["runbooks"]), "status_pages": len(document["status_pages"])}
        return apply_configuration(db, document, prune=bool(args.prune), dry_run=bool(args.dry_run))

    if args.command == "agent-request":
        raw_body = str(getattr(args, "raw_body", ""))
        cfg = load_monitoring_config(root)
        payload = process_agent_request(
            db,
            raw_body,
            str(args.node_key),
            str(args.timestamp),
            str(args.nonce),
            str(args.signature),
            str(args.remote_address),
            cfg,
        )
        return {"ok": True, "status_code": 200, "payload": payload}

    if args.command == "nodes":
        if args.action == "list":
            return list_nodes(db)
        if args.action == "provision":
            return provision_node(db, str(args.node_key), str(args.display_name), str(args.region), str(args.zone))
        status = {"activate": "active", "pause": "paused", "revoke": "revoked"}[str(args.action)]
        return set_node_status(db, str(args.node_key), status)

    if args.command == "manual-check":
        cfg = load_monitoring_config(root)
        if str(args.http_methods or "").strip() != "":
            cfg["http_methods"] = str(args.http_methods)
        if str(args.http_redirect_modes or "").strip() != "":
            cfg["http_redirect_modes"] = str(args.http_redirect_modes)
        if str(args.http_primary_method or "").strip() != "":
            cfg["http_primary_method"] = str(args.http_primary_method)
        if str(args.http_primary_redirect or "").strip() != "":
            cfg["http_primary_redirect"] = str(args.http_primary_redirect)
        return run_manual_check(str(args.site_url or ""), str(args.probe_type or "http"), cfg)

    return {"ok": False, "status_code": 400, "message": "Unknown command."}


def main(argv: list[str] | None = None) -> int:
    args = _parse_args(argv or sys.argv[1:])
    root = _monitoring_root(args.root)

    db: Database | None = None
    try:
        if args.command == "node-secret":
            result = {"ok": True, "node_key": str(args.node_key), "secret": derive_node_secret(str(args.node_key))}
            print(json.dumps(result, ensure_ascii=False))
            return 0
        if args.command == "agent-request":
            args.raw_body = sys.stdin.read()
            verify_signature(
                str(args.node_key),
                str(args.timestamp),
                str(args.nonce),
                str(args.signature),
                str(args.raw_body),
            )
        db = _database_from_root(root)
        result = _run_command(args, root, db)
        if args.command == "monitor":
            write_monitor_state(db, result)
        elif args.command == "consensus":
            write_monitor_state(db, result, engine="consensus", checked_by="agents")
        elif args.command in {"hourly", "daily"}:
            write_aggregation_state(db, args.command, result)
        if args.command == "config" and args.action == "export":
            print(str(result.get("rendered") or ""), end="")
        else:
            print(json.dumps(result, ensure_ascii=False, default=str))
        return 0 if result.get("ok") else 1
    except DistributedError as exc:
        print(json.dumps({"ok": False, "status_code": exc.status_code, "message": str(exc)}, ensure_ascii=False))
        return 1
    except Exception as exc:
        if db is not None:
            try:
                if args.command == "monitor":
                    write_monitor_state(db, None, error=str(exc))
                elif args.command == "consensus":
                    write_monitor_state(db, None, error=str(exc), engine="consensus", checked_by="agents")
                elif args.command in {"hourly", "daily"}:
                    write_aggregation_state(db, args.command, None, error=str(exc))
            except Exception:
                pass
        print(
            json.dumps(
                {
                    "ok": False,
                    "status_code": 500,
                    "message": f"Monitoring Python error: {exc}",
                },
                ensure_ascii=False,
            )
        )
        return 1
    finally:
        if db is not None:
            try:
                db.close()
            except Exception:
                pass


if __name__ == "__main__":
    raise SystemExit(main())
