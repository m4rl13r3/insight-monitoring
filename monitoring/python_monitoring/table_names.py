from __future__ import annotations

import re
from typing import Dict


_IDENT_RE = re.compile(r"^[A-Za-z0-9_]+$")
_TRUTHY = {"1", "true", "yes", "on"}


def _safe_ident(value: str) -> str:
    ident = (value or "").strip()
    if not ident or not _IDENT_RE.match(ident):
        raise ValueError(f"Invalid SQL identifier: {value!r}")
    return ident


def table_name(base: str, cfg: Dict[str, str]) -> str:
    suffix = str(cfg.get("table_suffix", "") or "").strip()
    if suffix and not _IDENT_RE.match(suffix):
        raise ValueError(f"Invalid table suffix: {suffix!r}")

    return _safe_ident(f"{base}{suffix}")


def qident(name: str) -> str:
    safe = _safe_ident(name)
    return f"`{safe}`"


def table_sql(base: str, cfg: Dict[str, str]) -> str:
    return qident(table_name(base, cfg))


def is_truthy(value: str | None) -> bool:
    return str(value or "").strip().lower() in _TRUTHY


def is_shadow_mode(cfg: Dict[str, str]) -> bool:
    return is_truthy(cfg.get("shadow_mode"))
