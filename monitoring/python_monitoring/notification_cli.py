from __future__ import annotations

import argparse
import json
import sys
from pathlib import Path

if __package__ in (None, ""):
    sys.path.insert(0, str(Path(__file__).resolve().parent.parent))

from python_monitoring.config import load_monitoring_config
from python_monitoring.db import Database, DbConfig
from python_monitoring.notifications import DEFAULT_TEMPLATES, dispatch_event, render_templates, send_channel, subscriber_smtp_config


def _database(root: Path, cfg: dict[str, str]) -> Database:
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


def _payload() -> dict:
    decoded = json.loads(sys.stdin.read() or "{}")
    if not isinstance(decoded, dict):
        raise ValueError("Invalid JSON payload.")
    return decoded


def main(argv: list[str] | None = None) -> int:
    parser = argparse.ArgumentParser(description="Insight notifications")
    parser.add_argument("action", choices=["send", "subscriber", "dispatch", "render"])
    parser.add_argument("--root", default=None)
    parser.add_argument("--force", action="store_true")
    args = parser.parse_args(argv or sys.argv[1:])
    root = Path(args.root).resolve() if args.root else Path(__file__).resolve().parent.parent
    try:
        payload = _payload()
        event_key = str(payload.get("event") or "test")
        context = payload.get("context") if isinstance(payload.get("context"), dict) else {}
        if args.action == "send":
            channel = payload.get("channel") if isinstance(payload.get("channel"), dict) else {}
            templates = payload.get("templates") if isinstance(payload.get("templates"), dict) else DEFAULT_TEMPLATES.get(event_key, DEFAULT_TEMPLATES["test"])
            result = send_channel(channel, event_key, context, templates)
        elif args.action == "subscriber":
            cfg = load_monitoring_config(root)
            database = _database(root, cfg)
            try:
                config = subscriber_smtp_config(database, cfg)
            finally:
                database.close()
            if not config:
                raise RuntimeError("No enabled SMTP channel is available for status page subscribers.")
            email = str(payload.get("email") or "").strip()
            templates = payload.get("templates") if isinstance(payload.get("templates"), dict) else DEFAULT_TEMPLATES["test"]
            result = send_channel({"name": "Status page subscribers", "provider": "smtp", "config": {**config, "to": email}}, event_key, context, templates)
        elif args.action == "render":
            templates = payload.get("templates") if isinstance(payload.get("templates"), dict) else DEFAULT_TEMPLATES.get(event_key, DEFAULT_TEMPLATES["test"])
            rendered = render_templates(event_key, context, templates, str(payload.get("channel_name") or "Insight"))
            result = {"ok": True, **rendered}
        else:
            cfg = load_monitoring_config(root)
            database = _database(root, cfg)
            try:
                result = dispatch_event(
                    database,
                    cfg,
                    event_key,
                    context,
                    force=bool(args.force),
                    idempotency_key=str(payload.get("idempotency_key") or ""),
                )
            finally:
                database.close()
        print(json.dumps(result, ensure_ascii=False, default=str))
        return 0 if result.get("ok") else 1
    except Exception as exc:
        print(json.dumps({"ok": False, "error": str(exc)}, ensure_ascii=False))
        return 1


if __name__ == "__main__":
    raise SystemExit(main())
