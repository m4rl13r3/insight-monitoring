from __future__ import annotations

import json
from typing import Any, Dict, Optional

from .db import Database


CREATE_TABLE_SQL = """
CREATE TABLE IF NOT EXISTS monitoring_data_comparisons (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    comparison_key VARCHAR(128) NOT NULL,
    scope VARCHAR(32) NOT NULL DEFAULT 'monitoring',
    site_id INT NULL,
    period_start DATETIME NULL,
    period_end DATETIME NULL,
    source_left VARCHAR(32) NOT NULL,
    source_right VARCHAR(32) NOT NULL,
    value_left_decimal DECIMAL(20,6) NULL,
    value_right_decimal DECIMAL(20,6) NULL,
    value_left_text VARCHAR(255) NULL,
    value_right_text VARCHAR(255) NULL,
    abs_diff DECIMAL(20,6) NULL,
    rel_diff_pct DECIMAL(10,4) NULL,
    tolerance_abs DECIMAL(20,6) NULL,
    tolerance_pct DECIMAL(10,4) NULL,
    is_match TINYINT(1) NOT NULL DEFAULT 0,
    details_json LONGTEXT NULL,
    compared_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    INDEX idx_mdc_key_period (comparison_key, period_start, period_end),
    INDEX idx_mdc_site_time (site_id, compared_at),
    INDEX idx_mdc_scope_time (scope, compared_at),
    INDEX idx_mdc_match_time (is_match, compared_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
"""


def ensure_comparisons_table(db: Database) -> None:
    db.execute(CREATE_TABLE_SQL)


def _to_float(value: Any) -> Optional[float]:
    if value is None:
        return None
    try:
        return float(value)
    except Exception:
        return None


def _as_text(value: Any) -> Optional[str]:
    if value is None:
        return None
    text = str(value).strip()
    return text if text else None


def record_comparison(
    db: Database,
    *,
    comparison_key: str,
    source_left: str,
    source_right: str,
    value_left: Any,
    value_right: Any,
    site_id: Optional[int] = None,
    scope: str = "monitoring",
    period_start: Optional[str] = None,
    period_end: Optional[str] = None,
    tolerance_abs: float = 0.0,
    tolerance_pct: float = 0.0,
    details: Optional[Dict[str, Any]] = None,
) -> Dict[str, Any]:
    ensure_comparisons_table(db)

    left_num = _to_float(value_left)
    right_num = _to_float(value_right)

    left_text = _as_text(value_left)
    right_text = _as_text(value_right)

    abs_diff: Optional[float] = None
    rel_diff_pct: Optional[float] = None

    if left_num is not None and right_num is not None:
        abs_diff = abs(left_num - right_num)
        if left_num == 0.0:
            rel_diff_pct = 0.0 if right_num == 0.0 else 100.0
        else:
            rel_diff_pct = abs_diff / abs(left_num) * 100.0

        within_abs = abs_diff <= max(0.0, float(tolerance_abs))
        within_pct = rel_diff_pct <= max(0.0, float(tolerance_pct))
        is_match = within_abs or within_pct
    else:
        is_match = (left_text or "") == (right_text or "")

    details_json = None
    if details:
        details_json = json.dumps(details, ensure_ascii=False)

    db.execute(
        """
        INSERT INTO monitoring_data_comparisons (
            comparison_key,
            scope,
            site_id,
            period_start,
            period_end,
            source_left,
            source_right,
            value_left_decimal,
            value_right_decimal,
            value_left_text,
            value_right_text,
            abs_diff,
            rel_diff_pct,
            tolerance_abs,
            tolerance_pct,
            is_match,
            details_json,
            compared_at
        ) VALUES (
            %s, %s, %s, %s, %s, %s, %s,
            %s, %s, %s, %s,
            %s, %s, %s, %s, %s,
            %s, NOW()
        )
        """,
        (
            comparison_key,
            scope,
            site_id,
            period_start,
            period_end,
            source_left,
            source_right,
            left_num,
            right_num,
            left_text,
            right_text,
            abs_diff,
            rel_diff_pct,
            float(tolerance_abs),
            float(tolerance_pct),
            1 if is_match else 0,
            details_json,
        ),
    )

    return {
        "ok": True,
        "comparison_key": comparison_key,
        "site_id": site_id,
        "source_left": source_left,
        "source_right": source_right,
        "value_left": left_num if left_num is not None else left_text,
        "value_right": right_num if right_num is not None else right_text,
        "abs_diff": abs_diff,
        "rel_diff_pct": rel_diff_pct,
        "is_match": bool(is_match),
    }
