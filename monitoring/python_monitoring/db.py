from __future__ import annotations

from dataclasses import dataclass
from typing import Any, Dict, Iterable, List, Optional, Sequence


class DbDriverError(RuntimeError):
    pass


def _import_driver():
    try:
        import mysql.connector  # type: ignore

        return ("mysql.connector", mysql.connector)
    except Exception:
        pass

    try:
        import pymysql  # type: ignore

        return ("pymysql", pymysql)
    except Exception:
        pass

    raise DbDriverError(
        "No MySQL Python driver available. Install one: "
        "pip install pymysql"
    )


@dataclass
class DbConfig:
    host: str
    user: str
    password: str
    database: str
    port: int = 3306
    socket: str = ""


class Database:
    def __init__(self, cfg: DbConfig):
        self.cfg = cfg
        self.driver_name, driver = _import_driver()
        self._conn = None

        socket_candidates = []
        if str(cfg.socket).strip():
            socket_candidates.append(str(cfg.socket).strip())

        if str(cfg.host).strip().lower() == "localhost":
            socket_candidates.extend(
                [
                    "/var/lib/mysql/mysql.sock",
                    "/tmp/mysql.sock",
                    "/run/mysqld/mysqld.sock",
                ]
            )

        hosts = [cfg.host]
        if str(cfg.host).strip().lower() == "localhost":
            hosts.append("127.0.0.1")

        last_exc: Exception | None = None
        endpoints = [("socket", s) for s in socket_candidates if s]
        endpoints.extend(("host", h) for h in hosts if h)

        for mode, value in endpoints:
            try:
                if self.driver_name == "mysql.connector":
                    kwargs = {
                        "user": cfg.user,
                        "password": cfg.password,
                        "database": cfg.database,
                        "charset": "utf8mb4",
                    }
                    if mode == "socket":
                        kwargs["unix_socket"] = value
                    else:
                        kwargs["host"] = value
                        kwargs["port"] = cfg.port
                    self._conn = driver.connect(**kwargs)
                else:
                    kwargs = {
                        "user": cfg.user,
                        "password": cfg.password,
                        "database": cfg.database,
                        "charset": "utf8mb4",
                        "autocommit": True,
                        "cursorclass": driver.cursors.DictCursor,
                    }
                    if mode == "socket":
                        kwargs["unix_socket"] = value
                    else:
                        kwargs["host"] = value
                        kwargs["port"] = cfg.port
                    self._conn = driver.connect(**kwargs)
                break
            except Exception as exc:
                last_exc = exc
                continue

        if self._conn is None:
            if last_exc is not None:
                raise last_exc
            raise DbDriverError("Unable to establish MySQL connection.")

        try:
            self._conn.autocommit = True
        except Exception:
            try:
                self._conn.autocommit(True)
            except Exception:
                pass

    def close(self) -> None:
        self._conn.close()

    def _cursor(self):
        if self.driver_name == "mysql.connector":
            return self._conn.cursor(dictionary=True)
        return self._conn.cursor()

    def query_all(self, sql: str, params: Sequence[Any] = ()) -> List[Dict[str, Any]]:
        cur = self._cursor()
        try:
            cur.execute(sql, tuple(params))
            rows = cur.fetchall()
            return list(rows or [])
        finally:
            cur.close()

    def query_one(self, sql: str, params: Sequence[Any] = ()) -> Optional[Dict[str, Any]]:
        cur = self._cursor()
        try:
            cur.execute(sql, tuple(params))
            return cur.fetchone()
        finally:
            cur.close()

    def execute(self, sql: str, params: Sequence[Any] = ()) -> int:
        cur = self._cursor()
        try:
            cur.execute(sql, tuple(params))
            try:
                self._conn.commit()
            except Exception:
                pass
            return int(getattr(cur, "rowcount", 0) or 0)
        finally:
            cur.close()

    def executemany(self, sql: str, items: Iterable[Sequence[Any]]) -> int:
        cur = self._cursor()
        try:
            cur.executemany(sql, list(items))
            try:
                self._conn.commit()
            except Exception:
                pass
            return int(getattr(cur, "rowcount", 0) or 0)
        finally:
            cur.close()
