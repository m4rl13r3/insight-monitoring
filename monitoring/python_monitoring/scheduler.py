from __future__ import annotations

import json
import logging
import os
import signal
import socket
import subprocess
import sys
import time
from logging.handlers import RotatingFileHandler
from pathlib import Path

if __package__ in (None, ""):
    sys.path.insert(0, str(Path(__file__).resolve().parent.parent))

from python_monitoring.config import load_monitoring_config
from python_monitoring.db import Database, DbConfig


def _bounded_interval() -> int:
    try:
        value = int(str(os.getenv("INSIGHT_MONITOR_INTERVAL_SEC", "60")).strip())
    except Exception:
        value = 60
    return max(10, min(86400, value))


def _monitor_tick_interval() -> int:
    interval = _bounded_interval()
    enabled = str(os.getenv("INSIGHT_REINFORCED_MONITORING_ENABLED", "1")).strip().lower() in {
        "1",
        "true",
        "yes",
        "on",
    }
    if not enabled:
        return interval
    try:
        reinforced = int(str(os.getenv("INSIGHT_REINFORCED_MONITOR_INTERVAL_SEC", "10")).strip())
    except Exception:
        reinforced = 10
    return min(interval, max(10, min(300, reinforced)))


def _logger(log_path: Path) -> logging.Logger:
    logger = logging.getLogger("insight.scheduler")
    logger.setLevel(logging.INFO)
    if not logger.handlers:
        handler = RotatingFileHandler(log_path, maxBytes=5_000_000, backupCount=3, encoding="utf-8")
        handler.setFormatter(logging.Formatter("%(asctime)s %(levelname)s %(message)s"))
        logger.addHandler(handler)
    return logger


def _run(root: Path, command: str, logger: logging.Logger) -> bool:
    try:
        completed = subprocess.run(
            [sys.executable, str(root / "python_monitoring" / "cli.py"), "--root", str(root), command],
            capture_output=True,
            text=True,
            encoding="utf-8",
            errors="replace",
            timeout=900,
            check=False,
        )
    except subprocess.TimeoutExpired:
        logger.error("%s timed out", command)
        return False
    output = (completed.stdout or "").strip()
    if output:
        try:
            logger.info("%s %s", command, json.dumps(json.loads(output), ensure_ascii=False, default=str))
        except Exception:
            logger.info("%s %s", command, output)
    if completed.stderr:
        logger.error("%s %s", command, completed.stderr.strip())
    if completed.returncode != 0:
        logger.error("%s exited with code %d", command, completed.returncode)
    return completed.returncode == 0


def _database(root: Path) -> Database:
    cfg = load_monitoring_config(root)
    return Database(
        DbConfig(
            host=cfg.get("servername", "localhost"),
            user=cfg.get("username", ""),
            password=cfg.get("password", ""),
            database=cfg.get("dbname", "insight"),
            port=int(cfg.get("port", "3306") or 3306),
            socket=cfg.get("db_socket", ""),
        )
    )


def _record_lease(db: Database, owner_id: str, active: bool) -> None:
    db.execute(
        """
        CREATE TABLE IF NOT EXISTS monitoring_worker_leases (
            lease_name VARCHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
            owner_id VARCHAR(128) NOT NULL,
            acquired_at DATETIME(3) NOT NULL,
            heartbeat_at DATETIME(3) NOT NULL,
            expires_at DATETIME(3) NOT NULL,
            PRIMARY KEY (lease_name),
            KEY idx_monitoring_worker_leases_expiry (expires_at)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
        """
    )
    if active:
        db.execute(
            """
            INSERT INTO monitoring_worker_leases (lease_name, owner_id, acquired_at, heartbeat_at, expires_at)
            VALUES ('scheduler', %s, CURRENT_TIMESTAMP(3), CURRENT_TIMESTAMP(3), DATE_ADD(CURRENT_TIMESTAMP(3), INTERVAL 15 SECOND))
            ON DUPLICATE KEY UPDATE owner_id = VALUES(owner_id), heartbeat_at = VALUES(heartbeat_at), expires_at = VALUES(expires_at)
            """,
            (owner_id,),
        )


def main() -> int:
    root = Path(__file__).resolve().parent.parent
    runtime_dir = root / "runtime"
    logs_dir = root / "logs"
    runtime_dir.mkdir(parents=True, exist_ok=True)
    logs_dir.mkdir(parents=True, exist_ok=True)
    logger = _logger(logs_dir / "scheduler.log")
    interval = _monitor_tick_interval()
    mode = str(os.getenv("INSIGHT_DISTRIBUTED_MODE", "standalone")).strip().lower()
    monitor_command = "consensus" if mode == "hub" else "monitor"
    running = True

    def stop(_signum: int, _frame: object) -> None:
        nonlocal running
        running = False

    signal.signal(signal.SIGTERM, stop)
    signal.signal(signal.SIGINT, stop)
    next_monitor = 0.0
    last_hour = ""
    last_day = ""
    logger.info("scheduler started mode=%s interval=%d", mode, interval)
    owner_id = f"{socket.gethostname()}:{os.getpid()}"
    db: Database | None = None
    lock_name = "insight:scheduler"
    lock_active = False
    try:
        while running:
            now = time.time()
            (runtime_dir / "scheduler.heartbeat").touch()
            if db is None:
                try:
                    db = _database(root)
                except Exception as exc:
                    logger.warning("scheduler database unavailable: %s", exc)
                    time.sleep(5)
                    continue
            try:
                if not lock_active:
                    lock_active = db.acquire_advisory_lock(lock_name, 0)
                    if lock_active:
                        logger.info("scheduler lease acquired owner=%s", owner_id)
                        next_monitor = 0.0
                        last_hour = ""
                        last_day = ""
                _record_lease(db, owner_id, lock_active)
            except Exception as exc:
                logger.warning("scheduler lease connection lost: %s", exc)
                try:
                    db.close()
                except Exception:
                    pass
                db = None
                lock_active = False
                time.sleep(2)
                continue
            if not lock_active:
                time.sleep(3)
                continue
            current = time.localtime(now)
            hour_key = time.strftime("%Y%m%d%H", current)
            day_key = time.strftime("%Y%m%d", current)
            if now >= next_monitor:
                _run(root, monitor_command, logger)
                next_monitor = now + interval
            hourly_ok = True
            if hour_key != last_hour:
                hourly_ok = _run(root, "hourly", logger)
                last_hour = hour_key
            if day_key != last_day:
                daily_ok = _run(root, "daily", logger)
                if hourly_ok and daily_ok:
                    _run(root, "retention", logger)
                last_day = day_key
            time.sleep(1)
    finally:
        if db is not None:
            try:
                if lock_active:
                    db.release_advisory_lock(lock_name)
                db.close()
            except Exception:
                pass
    logger.info("scheduler stopped")
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
