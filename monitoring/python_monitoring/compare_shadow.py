from __future__ import annotations

from datetime import datetime, timedelta
from typing import Any, Dict, Iterable, Tuple

from .comparison import ensure_comparisons_table, record_comparison
from .db import Database
from .table_names import table_name, table_sql


def _as_date_string(value: Any) -> str:
    if isinstance(value, datetime):
        return value.strftime("%Y-%m-%d")
    raw = str(value or "").strip()
    return raw[:10]


def _to_period_hour(date_value: str, hour_value: int) -> Tuple[str, str]:
    start = f"{date_value} {hour_value:02d}:00:00"
    end = f"{date_value} {hour_value:02d}:59:59"
    return start, end


def _to_period_day(date_value: str) -> Tuple[str, str]:
    start = f"{date_value} 00:00:00"
    end = f"{date_value} 23:59:59"
    return start, end


def _last_completed_hour(now: datetime | None = None) -> Tuple[str, int]:
    dt = now or datetime.now()
    target = dt.replace(minute=0, second=0, microsecond=0) - timedelta(hours=1)
    return target.strftime("%Y-%m-%d"), int(target.hour)


def _yesterday(now: datetime | None = None) -> str:
    dt = now or datetime.now()
    return (dt.date() - timedelta(days=1)).strftime("%Y-%m-%d")


def _count_by_site(db: Database, table_sql_name: str, date_value: str, hour_value: int) -> Dict[int, int]:
    rows = db.query_all(
        f"""
        SELECT site_id, COUNT(*) AS cnt
        FROM {table_sql_name}
        WHERE DATE(checked_at) = %s AND HOUR(checked_at) = %s
        GROUP BY site_id
        """,
        (date_value, hour_value),
    )
    out: Dict[int, int] = {}
    for row in rows:
        site_id = int(row.get("site_id") or 0)
        if site_id <= 0:
            continue
        out[site_id] = int(row.get("cnt") or 0)
    return out


def compare_shadow_hourly(db: Database, cfg: Dict[str, str], date_value: str | None = None, hour_value: int | None = None) -> Dict[str, Any]:
    ensure_comparisons_table(db)

    php_hourly_sql = table_sql("hourly_stats", {"table_suffix": ""})
    py_hourly = table_name("hourly_stats", cfg)
    py_hourly_sql = table_sql("hourly_stats", cfg)

    php_probes_sql = table_sql("probes", {"table_suffix": ""})
    py_probes = table_name("probes", cfg)
    py_probes_sql = table_sql("probes", cfg)

    if not date_value or hour_value is None:
        auto_date, auto_hour = _last_completed_hour()
        date_value = date_value or auto_date
        hour_value = auto_hour if hour_value is None else int(hour_value)

    date_value = _as_date_string(date_value)
    hour_value = int(hour_value)
    period_start, period_end = _to_period_hour(date_value, hour_value)

    compared_rows = 0
    joined = db.query_all(
        f"""
        SELECT
            p.site_id,
            p.avg_response_time AS php_avg_response_time,
            y.avg_response_time AS py_avg_response_time,
            p.minutes_offline AS php_minutes_offline,
            y.minutes_offline AS py_minutes_offline,
            p.binary_sequence AS php_binary,
            y.binary_sequence AS py_binary
        FROM {php_hourly_sql} p
        INNER JOIN {py_hourly_sql} y
            ON y.site_id = p.site_id
           AND y.date = p.date
           AND y.hour = p.hour
        WHERE p.date = %s AND p.hour = %s
        """,
        (date_value, hour_value),
    )

    for row in joined:
        site_id = int(row.get("site_id") or 0)
        if site_id <= 0:
            continue

        record_comparison(
            db,
            comparison_key="hourly.avg_response_time",
            scope="monitoring",
            site_id=site_id,
            period_start=period_start,
            period_end=period_end,
            source_left="php",
            source_right="python",
            value_left=row.get("php_avg_response_time"),
            value_right=row.get("py_avg_response_time"),
            tolerance_abs=5.0,
            tolerance_pct=2.0,
            details={"date": date_value, "hour": hour_value},
        )
        compared_rows += 1

        record_comparison(
            db,
            comparison_key="hourly.minutes_offline",
            scope="monitoring",
            site_id=site_id,
            period_start=period_start,
            period_end=period_end,
            source_left="php",
            source_right="python",
            value_left=row.get("php_minutes_offline"),
            value_right=row.get("py_minutes_offline"),
            tolerance_abs=0.0,
            tolerance_pct=0.0,
            details={
                "date": date_value,
                "hour": hour_value,
                "php_binary": row.get("php_binary"),
                "py_binary": row.get("py_binary"),
            },
        )
        compared_rows += 1

    php_counts = _count_by_site(db, php_probes_sql, date_value, hour_value)
    py_counts = _count_by_site(db, py_probes_sql, date_value, hour_value)

    for site_id in sorted(set(php_counts.keys()) | set(py_counts.keys())):
        record_comparison(
            db,
            comparison_key="probes.hourly_count",
            scope="monitoring",
            site_id=site_id,
            period_start=period_start,
            period_end=period_end,
            source_left="php",
            source_right="python",
            value_left=php_counts.get(site_id, 0),
            value_right=py_counts.get(site_id, 0),
            tolerance_abs=0.0,
            tolerance_pct=0.0,
            details={"date": date_value, "hour": hour_value},
        )
        compared_rows += 1

    return {
        "ok": True,
        "mode": "hourly",
        "date": date_value,
        "hour": hour_value,
        "rows_logged": compared_rows,
        "tables": {
            "hourly_php": "hourly_stats",
            "hourly_python": py_hourly,
            "probes_php": "probes",
            "probes_python": py_probes,
        },
    }


def compare_shadow_daily(db: Database, cfg: Dict[str, str], date_value: str | None = None) -> Dict[str, Any]:
    ensure_comparisons_table(db)

    php_daily_sql = table_sql("daily_stats", {"table_suffix": ""})
    py_daily = table_name("daily_stats", cfg)
    py_daily_sql = table_sql("daily_stats", cfg)

    if not date_value:
        date_value = _yesterday()

    date_value = _as_date_string(date_value)
    period_start, period_end = _to_period_day(date_value)

    compared_rows = 0
    joined = db.query_all(
        f"""
        SELECT
            p.site_id,
            p.avg_response_time AS php_avg_response_time,
            y.avg_response_time AS py_avg_response_time,
            p.minutes_offline AS php_minutes_offline,
            y.minutes_offline AS py_minutes_offline
        FROM {php_daily_sql} p
        INNER JOIN {py_daily_sql} y
            ON y.site_id = p.site_id
           AND y.date = p.date
        WHERE p.date = %s
        """,
        (date_value,),
    )

    for row in joined:
        site_id = int(row.get("site_id") or 0)
        if site_id <= 0:
            continue

        record_comparison(
            db,
            comparison_key="daily.avg_response_time",
            scope="monitoring",
            site_id=site_id,
            period_start=period_start,
            period_end=period_end,
            source_left="php",
            source_right="python",
            value_left=row.get("php_avg_response_time"),
            value_right=row.get("py_avg_response_time"),
            tolerance_abs=5.0,
            tolerance_pct=2.0,
            details={"date": date_value},
        )
        compared_rows += 1

        record_comparison(
            db,
            comparison_key="daily.minutes_offline",
            scope="monitoring",
            site_id=site_id,
            period_start=period_start,
            period_end=period_end,
            source_left="php",
            source_right="python",
            value_left=row.get("php_minutes_offline"),
            value_right=row.get("py_minutes_offline"),
            tolerance_abs=0.0,
            tolerance_pct=0.0,
            details={"date": date_value},
        )
        compared_rows += 1

    return {
        "ok": True,
        "mode": "daily",
        "date": date_value,
        "rows_logged": compared_rows,
        "tables": {
            "daily_php": "daily_stats",
            "daily_python": py_daily,
        },
    }
