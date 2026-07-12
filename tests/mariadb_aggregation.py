from __future__ import annotations

import sys
import uuid
from datetime import timedelta
from pathlib import Path


ROOT = Path(__file__).resolve().parents[1]
sys.path.insert(0, str(ROOT / "monitoring"))

from python_monitoring.actions import add_site, delete_site
from python_monitoring.aggregation_state import database_now
from python_monitoring.config import load_monitoring_config
from python_monitoring.daily import run_daily_job
from python_monitoring.db import Database, DbConfig
from python_monitoring.hourly import run_hourly_job
from python_monitoring.retention import run_retention_job


cfg = load_monitoring_config(ROOT / "monitoring")
db = Database(
    DbConfig(
        host=cfg.get("servername", "db"),
        user=cfg.get("username", ""),
        password=cfg.get("password", ""),
        database=cfg.get("dbname", ""),
        port=int(cfg.get("port", "3306") or 3306),
        socket=cfg.get("db_socket", ""),
    )
)

target = f"https://aggregation-{uuid.uuid4().hex}.example.test"
site_id = 0
try:
    created = add_site(db, target, "http", calc_method="time_weighted")
    if not created.get("ok"):
        raise RuntimeError("La cible d’agrégation n’a pas été créée.")
    site = db.query_one("SELECT id FROM sites WHERE url = %s LIMIT 1", (target,)) or {}
    site_id = int(site.get("id") or 0)
    if site_id <= 0:
        raise RuntimeError("La cible d’agrégation est introuvable.")

    now = database_now(db)
    slot = (now - timedelta(days=1)).replace(hour=12, minute=0, second=0, microsecond=0)
    checks = [
        (slot + timedelta(minutes=15), "online", 20.0),
        (slot + timedelta(minutes=30), "offline", None),
        (slot + timedelta(minutes=45), "online", 40.0),
    ]
    for checked_at, status, response_time in checks:
        db.execute(
            "INSERT INTO probes (site_id, probe_type, status, response_time, checked_by, checked_at) VALUES (%s, 'http', %s, %s, 'pyt', %s)",
            (site_id, status, response_time, checked_at.strftime("%Y-%m-%d %H:%M:%S")),
        )

    test_cfg = dict(cfg)
    test_cfg["aggregation_reprocess_hours"] = "72"
    hourly_result = run_hourly_job(db, test_cfg)
    if int(hourly_result.get("bad_data", 0)) != 0:
        raise RuntimeError("L’agrégation horaire a rejeté des données valides.")
    hourly = db.query_one(
        "SELECT avg_response_time, sample_count, response_time_sum, total_seconds, offline_seconds, unknown_seconds, availability_ratio, calc_method FROM hourly_stats WHERE site_id = %s AND date = %s AND hour = %s LIMIT 1",
        (site_id, slot.strftime("%Y-%m-%d"), slot.hour),
    ) or {}
    if int(hourly.get("total_seconds") or 0) != 3600:
        raise RuntimeError("La durée horaire agrégée est invalide.")
    if int(hourly.get("offline_seconds") or 0) != 900:
        raise RuntimeError("La durée hors ligne agrégée est invalide.")
    if int(hourly.get("unknown_seconds") or 0) != 900:
        raise RuntimeError("La durée inconnue agrégée est invalide.")
    if int(hourly.get("sample_count") or 0) != 2 or abs(float(hourly.get("response_time_sum") or 0.0) - 60.0) > 0.001:
        raise RuntimeError("La pondération des temps de réponse horaires est invalide.")
    if abs(float(hourly.get("avg_response_time") or 0.0) - 30.0) > 0.001:
        raise RuntimeError("Le temps de réponse horaire est invalide.")
    if abs(float(hourly.get("availability_ratio") or 0.0) - 0.6667) > 0.0001:
        raise RuntimeError("La disponibilité horaire est invalide.")
    if str(hourly.get("calc_method") or "") != "time_weighted":
        raise RuntimeError("La méthode d’agrégation horaire est invalide.")

    daily_result = run_daily_job(db, test_cfg)
    if int(daily_result.get("bad_data", 0)) != 0:
        raise RuntimeError("L’agrégation journalière a rejeté des données valides.")
    daily = db.query_one(
        "SELECT avg_response_time, sample_count, response_time_sum, total_seconds, offline_seconds, unknown_seconds, availability_ratio, calc_method FROM daily_stats WHERE site_id = %s AND date = %s LIMIT 1",
        (site_id, slot.strftime("%Y-%m-%d")),
    ) or {}
    if int(daily.get("unknown_seconds") or 0) != 900:
        raise RuntimeError("La durée inconnue journalière est invalide.")
    if abs(float(daily.get("availability_ratio") or 0.0) - 0.6667) > 0.0001:
        raise RuntimeError("La disponibilité journalière est invalide.")
    if int(daily.get("sample_count") or 0) != 2 or abs(float(daily.get("response_time_sum") or 0.0) - 60.0) > 0.001:
        raise RuntimeError("La pondération des temps de réponse journaliers est invalide.")
    if abs(float(daily.get("avg_response_time") or 0.0) - 30.0) > 0.001:
        raise RuntimeError("Le temps de réponse journalier est invalide.")

    old_slot = (now - timedelta(days=40)).replace(hour=8, minute=0, second=0, microsecond=0)
    old_date = old_slot.strftime("%Y-%m-%d")
    db.execute(
        "INSERT INTO probes (site_id, probe_type, status, response_time, checked_by, checked_at) VALUES (%s, 'http', 'online', 10, 'pyt', %s)",
        (site_id, old_slot.strftime("%Y-%m-%d %H:%M:%S")),
    )
    db.execute(
        "INSERT INTO hourly_stats (site_id, date, hour, avg_response_time, minutes_offline, total_seconds, offline_seconds, degraded_seconds, maintenance_seconds, unknown_seconds, availability_ratio, health_score, calc_method) VALUES (%s, %s, %s, 10, 0, 3600, 0, 0, 0, 0, 1, 1, 'time_weighted') ON DUPLICATE KEY UPDATE unknown_seconds = VALUES(unknown_seconds)",
        (site_id, old_date, old_slot.hour),
    )
    db.execute(
        "INSERT INTO daily_stats (site_id, date, avg_response_time, minutes_offline, total_seconds, offline_seconds, degraded_seconds, maintenance_seconds, unknown_seconds, availability_ratio, health_score, calc_method) VALUES (%s, %s, 10, 0, 86400, 0, 0, 0, 0, 1, 1, 'time_weighted') ON DUPLICATE KEY UPDATE unknown_seconds = VALUES(unknown_seconds)",
        (site_id, old_date),
    )
    db.execute(
        "INSERT INTO ssl_checks (site_id, host, checked_at) VALUES (%s, %s, %s)",
        (site_id, "aggregation.example.test", old_slot.strftime("%Y-%m-%d %H:%M:%S")),
    )

    retention_cfg = dict(test_cfg)
    retention_cfg.update(
        {
            "probe_retention_days": "2",
            "hourly_retention_days": "7",
            "daily_retention_days": "30",
            "tls_retention_days": "7",
            "retention_batch_size": "100",
        }
    )
    retention = run_retention_job(db, retention_cfg)
    if retention.get("skipped"):
        raise RuntimeError("La purge a été ignorée malgré des agrégations à jour.")
    for table, date_column in (("probes", "checked_at"), ("hourly_stats", "date"), ("daily_stats", "date"), ("ssl_checks", "checked_at")):
        remaining = db.query_one(
            f"SELECT COUNT(*) AS total FROM `{table}` WHERE site_id = %s AND {date_column} <= %s",
            (site_id, old_slot.strftime("%Y-%m-%d %H:%M:%S")),
        ) or {}
        if int(remaining.get("total") or 0) != 0:
            raise RuntimeError(f"La purge de {table} a échoué.")

    deleted = delete_site(db, site_id)
    if not deleted.get("ok"):
        raise RuntimeError("La suppression de la cible a échoué.")
    site_id = 0
    for table in ("probes", "hourly_stats", "daily_stats", "ssl_checks"):
        remaining = db.query_one(f"SELECT COUNT(*) AS total FROM `{table}` WHERE site_id = %s", (int(site.get("id") or 0),)) or {}
        if int(remaining.get("total") or 0) != 0:
            raise RuntimeError(f"La suppression en cascade de {table} a échoué.")

    print("Agrégations, rétention et suppression en cascade validées.")
finally:
    if site_id > 0:
        delete_site(db, site_id)
    db.close()
