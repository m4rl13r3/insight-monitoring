from __future__ import annotations

import argparse
import json
import sys
from pathlib import Path
from typing import Any, Dict

if __package__ in (None, ""):
    sys.path.insert(0, str(Path(__file__).resolve().parent.parent))

from python_monitoring.actions import add_site, delete_site, list_sites, update_site
from python_monitoring.comparison import ensure_comparisons_table, record_comparison
from python_monitoring.compare_shadow import compare_shadow_daily, compare_shadow_hourly
from python_monitoring.config import load_monitoring_config
from python_monitoring.daily import run_daily_job
from python_monitoring.db import Database, DbConfig
from python_monitoring.hourly import run_hourly_job
from python_monitoring.monitor import run_manual_check, run_monitor_job
from python_monitoring.shadow_tables import ensure_shadow_tables, seed_shadow_probes


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
    parser = argparse.ArgumentParser(description="Python monitoring bridge")
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

    subparsers.add_parser("hourly", help="Build hourly stats")
    subparsers.add_parser("daily", help="Build daily stats")
    subparsers.add_parser("monitor", help="Run monitoring checks")
    manual_check_parser = subparsers.add_parser("manual-check", help="Run one manual check without DB insert")
    manual_check_parser.add_argument("--site-url", default="")
    manual_check_parser.add_argument("--probe-type", default="http")
    manual_check_parser.add_argument("--http-methods", default="")
    manual_check_parser.add_argument("--http-redirect-modes", default="")
    manual_check_parser.add_argument("--http-primary-method", default="")
    manual_check_parser.add_argument("--http-primary-redirect", default="")
    subparsers.add_parser("ensure-comparisons-table", help="Create comparisons table if needed")
    subparsers.add_parser("ensure-shadow-tables", help="Create suffixed monitoring tables (ex: *_python)")

    seed_parser = subparsers.add_parser("seed-shadow-probes", help="Copy probes -> suffixed probes table")
    seed_parser.add_argument("--truncate-first", action="store_true")

    compare_hourly_parser = subparsers.add_parser("compare-shadow-hourly", help="Compare php vs python for one hour")
    compare_hourly_parser.add_argument("--date", default="")
    compare_hourly_parser.add_argument("--hour", type=int, default=-1)

    compare_daily_parser = subparsers.add_parser("compare-shadow-daily", help="Compare php vs python for one day")
    compare_daily_parser.add_argument("--date", default="")

    compare_parser = subparsers.add_parser("compare-log", help="Insert one comparison row")
    compare_parser.add_argument("--key", required=True)
    compare_parser.add_argument("--scope", default="monitoring")
    compare_parser.add_argument("--site-id", type=int, default=0)
    compare_parser.add_argument("--left-source", default="php")
    compare_parser.add_argument("--right-source", default="python")
    compare_parser.add_argument("--left-value", default="")
    compare_parser.add_argument("--right-value", default="")
    compare_parser.add_argument("--period-start", default="")
    compare_parser.add_argument("--period-end", default="")
    compare_parser.add_argument("--tolerance-abs", type=float, default=0.0)
    compare_parser.add_argument("--tolerance-pct", type=float, default=0.0)

    return parser.parse_args(argv)


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
        )

    if args.command == "hourly":
        cfg = load_monitoring_config(root)
        return run_hourly_job(db, cfg)

    if args.command == "daily":
        cfg = load_monitoring_config(root)
        return run_daily_job(db, cfg)

    if args.command == "monitor":
        cfg = load_monitoring_config(root)
        return run_monitor_job(db, cfg, root)

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

    if args.command == "ensure-comparisons-table":
        ensure_comparisons_table(db)
        return {"ok": True, "table": "monitoring_data_comparisons"}

    if args.command == "compare-log":
        site_id = int(args.site_id or 0)
        return record_comparison(
            db,
            comparison_key=str(args.key),
            scope=str(args.scope or "monitoring"),
            site_id=site_id if site_id > 0 else None,
            period_start=str(args.period_start or "") or None,
            period_end=str(args.period_end or "") or None,
            source_left=str(args.left_source or "php"),
            source_right=str(args.right_source or "python"),
            value_left=str(args.left_value),
            value_right=str(args.right_value),
            tolerance_abs=float(args.tolerance_abs or 0.0),
            tolerance_pct=float(args.tolerance_pct or 0.0),
        )

    if args.command == "ensure-shadow-tables":
        cfg = load_monitoring_config(root)
        return ensure_shadow_tables(db, cfg)

    if args.command == "seed-shadow-probes":
        cfg = load_monitoring_config(root)
        return seed_shadow_probes(db, cfg, truncate_first=bool(args.truncate_first))

    if args.command == "compare-shadow-hourly":
        cfg = load_monitoring_config(root)
        date_value = str(args.date or "") or None
        hour_value = None if int(args.hour) < 0 else int(args.hour)
        return compare_shadow_hourly(db, cfg, date_value=date_value, hour_value=hour_value)

    if args.command == "compare-shadow-daily":
        cfg = load_monitoring_config(root)
        date_value = str(args.date or "") or None
        return compare_shadow_daily(db, cfg, date_value=date_value)

    return {"ok": False, "status_code": 400, "message": "Commande inconnue."}


def main(argv: list[str] | None = None) -> int:
    args = _parse_args(argv or sys.argv[1:])
    root = _monitoring_root(args.root)

    db: Database | None = None
    try:
        db = _database_from_root(root)
        result = _run_command(args, root, db)
        print(json.dumps(result, ensure_ascii=False, default=str))
        return 0
    except Exception as exc:
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
