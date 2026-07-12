from __future__ import annotations

from typing import Dict, List

from .db import Database
from .table_names import table_name, table_sql


_BASE_TABLES = ["probes", "hourly_stats", "daily_stats", "incidents", "alert"]


def ensure_shadow_tables(db: Database, cfg: Dict[str, str]) -> Dict[str, object]:
    suffix = str(cfg.get("table_suffix", "") or "").strip()
    if suffix == "":
        raise ValueError("MONITORING_TABLE_SUFFIX is empty. Set something like _python.")

    created: List[str] = []
    for base in _BASE_TABLES:
        target = table_name(base, cfg)
        if target == base:
            continue
        db.execute(f"CREATE TABLE IF NOT EXISTS {table_sql(base, cfg)} LIKE `{base}`")
        created.append(target)

    ssl_target = table_name("ssl_checks", cfg)
    ssl_target_sql = table_sql("ssl_checks", cfg)
    if ssl_target != "ssl_checks":
        db.execute(
            f"""
            CREATE TABLE IF NOT EXISTS {ssl_target_sql} (
                id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
                site_id INT NOT NULL,
                host VARCHAR(255) NOT NULL,
                port INT NOT NULL DEFAULT 443,
                is_valid TINYINT(1) NULL,
                valid_from DATETIME NULL,
                valid_to DATETIME NULL,
                days_remaining INT NULL,
                issuer_name VARCHAR(255) NULL,
                issuer_cn VARCHAR(255) NULL,
                subject_cn VARCHAR(255) NULL,
                san TEXT NULL,
                tls_version VARCHAR(32) NULL,
                cipher_name VARCHAR(64) NULL,
                error_message VARCHAR(255) NULL,
                checked_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                PRIMARY KEY (id),
                INDEX idx_ssl_site_checked (site_id, checked_at),
                INDEX idx_ssl_valid_to (valid_to)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
            """
        )
        created.append(ssl_target)

    return {"ok": True, "created_tables": created}


def seed_shadow_probes(db: Database, cfg: Dict[str, str], truncate_first: bool = False) -> Dict[str, object]:
    suffix = str(cfg.get("table_suffix", "") or "").strip()
    if suffix == "":
        raise ValueError("MONITORING_TABLE_SUFFIX is empty. Set something like _python.")

    target_table = table_name("probes", cfg)
    target_table_sql = table_sql("probes", cfg)
    source_table_sql = "`probes`"

    db.execute(f"CREATE TABLE IF NOT EXISTS {target_table_sql} LIKE {source_table_sql}")

    if truncate_first:
        db.execute(f"TRUNCATE TABLE {target_table_sql}")

    inserted = db.execute(
        f"""
        INSERT INTO {target_table_sql}
            (site_id, probe_type, status, response_time, http_code, checked_at)
        SELECT
            site_id, probe_type, status, response_time, http_code, checked_at
        FROM {source_table_sql}
        """
    )

    return {"ok": True, "target_table": target_table, "inserted": int(inserted or 0)}
