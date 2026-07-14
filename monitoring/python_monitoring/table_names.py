from __future__ import annotations

import re
_IDENT_RE = re.compile(r"^[A-Za-z0-9_]+$")
_TRUTHY = {"1", "true", "yes", "on"}


def _safe_ident(value: str) -> str:
    ident = (value or "").strip()
    if not ident or not _IDENT_RE.match(ident):
        raise ValueError(f"Invalid SQL identifier: {value!r}")
    return ident


def table_name(base: str, _cfg: dict[str, str] | None = None) -> str:
    return _safe_ident(base)


def qident(name: str) -> str:
    safe = _safe_ident(name)
    return f"`{safe}`"


def table_sql(base: str, cfg: dict[str, str] | None = None) -> str:
    return qident(table_name(base, cfg))


def is_truthy(value: str | None) -> bool:
    return str(value or "").strip().lower() in _TRUTHY
