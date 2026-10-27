import os
import sqlite3
import threading
from typing import Any, Dict, List, Optional, Tuple, Union
import httpx
from app.config import settings
from app.core.errors import AppError, ErrorCode


class DatabaseClient:
    """
    Unified database client that connects to either:
    1. Cloudflare D1 via Worker Gateway API (Production / Oracle deployment)
    2. Local SQLite with thread-local connection (Local development & Unit Testing)
    """

    def __init__(self):
        self.mode = settings.DB_MODE.lower()
        self.worker_url = settings.D1_WORKER_URL.rstrip("/")
        self.service_token = settings.D1_SERVICE_TOKEN
        self.sqlite_path = settings.SQLITE_PATH
        self._local = threading.local()
        self._lock = threading.Lock()

    def _get_sqlite_conn(self) -> sqlite3.Connection:
        if not hasattr(self._local, "conn") or self._local.conn is None:
            conn = sqlite3.connect(
                self.sqlite_path,
                check_same_thread=False,
                timeout=30.0,
                isolation_level=None  # We manage transactions explicitly
            )
            conn.row_factory = sqlite3.Row
            # Enable foreign keys and WAL mode for high concurrency
            conn.execute("PRAGMA foreign_keys = ON;")
            conn.execute("PRAGMA journal_mode = WAL;")
            self._local.conn = conn
        return self._local.conn

    def close(self):
        if hasattr(self._local, "conn") and self._local.conn is not None:
            self._local.conn.close()
            self._local.conn = None

    async def execute(self, sql: str, params: Optional[Union[List[Any], Tuple[Any, ...]]] = None) -> int:
        """Executes a single non-returning or returning SQL statement."""
        params = list(params) if params else []
        if self.mode == "worker":
            return await self._execute_worker_query(sql, params)
        else:
            return self._execute_sqlite(sql, params)

    async def fetch_one(self, sql: str, params: Optional[Union[List[Any], Tuple[Any, ...]]] = None) -> Optional[Dict[str, Any]]:
        """Executes a SQL query and returns a single row as a dictionary."""
        params = list(params) if params else []
        if self.mode == "worker":
            rows = await self._query_worker(sql, params)
            return rows[0] if rows else None
        else:
            return self._fetch_one_sqlite(sql, params)

    async def fetch_all(self, sql: str, params: Optional[Union[List[Any], Tuple[Any, ...]]] = None) -> List[Dict[str, Any]]:
        """Executes a SQL query and returns all matching rows as dictionaries."""
        params = list(params) if params else []
        if self.mode == "worker":
            return await self._query_worker(sql, params)
        else:
            return self._fetch_all_sqlite(sql, params)

    async def execute_batch(self, statements: List[Tuple[str, List[Any]]]) -> bool:
        """
        Executes multiple statements inside a single atomic transaction.
        Essential for reservation, settlement, wallet top-up, and ledger consistency.
        """
        if not statements:
            return True

        if self.mode == "worker":
            return await self._execute_worker_batch(statements)
        else:
            return self._execute_sqlite_batch(statements)

    # ------------------ Local SQLite Methods ------------------

    def _execute_sqlite(self, sql: str, params: List[Any]) -> int:
        conn = self._get_sqlite_conn()
        cursor = conn.cursor()
        cursor.execute(sql, params)
        return cursor.rowcount

    def _fetch_one_sqlite(self, sql: str, params: List[Any]) -> Optional[Dict[str, Any]]:
        conn = self._get_sqlite_conn()
        cursor = conn.cursor()
        cursor.execute(sql, params)
        row = cursor.fetchone()
        return dict(row) if row is not None else None

    def _fetch_all_sqlite(self, sql: str, params: List[Any]) -> List[Dict[str, Any]]:
        conn = self._get_sqlite_conn()
        cursor = conn.cursor()
        cursor.execute(sql, params)
        rows = cursor.fetchall()
        return [dict(r) for r in rows]

    def _execute_sqlite_batch(self, statements: List[Tuple[str, List[Any]]]) -> bool:
        conn = self._get_sqlite_conn()
        with self._lock:
            cursor = conn.cursor()
            cursor.execute("BEGIN IMMEDIATE TRANSACTION;")
            try:
                for sql, params in statements:
                    cursor.execute(sql, params or [])
                cursor.execute("COMMIT;")
                return True
            except Exception as e:
                cursor.execute("ROLLBACK;")
                raise e

    # ------------------ Cloudflare Worker HTTP Methods ------------------

    async def _query_worker(self, sql: str, params: List[Any]) -> List[Dict[str, Any]]:
        if not self.worker_url or not self.service_token:
            raise AppError(
                code=ErrorCode.INTERNAL_ERROR,
                message="Cloudflare D1 Worker URL or Service Token is not configured",
                status_code=500
            )

        headers = {
            "Authorization": f"Bearer {self.service_token}",
            "Content-Type": "application/json"
        }
        payload = {"sql": sql, "params": params}

        async with httpx.AsyncClient(timeout=10.0) as client:
            try:
                resp = await client.post(f"{self.worker_url}/query", json=payload, headers=headers)
            except Exception as ex:
                raise AppError(
                    code=ErrorCode.INTERNAL_ERROR,
                    message=f"D1 Worker connection failure: {str(ex)}",
                    status_code=500
                )

        if resp.status_code != 200:
            err = resp.json().get("error", "D1 worker error")
            raise AppError(code=ErrorCode.INTERNAL_ERROR, message=f"D1 error: {err}", status_code=500)

        data = resp.json()
        return data.get("results", [])

    async def _execute_worker_query(self, sql: str, params: List[Any]) -> int:
        res = await self._query_worker(sql, params)
        return len(res)

    async def _execute_worker_batch(self, statements: List[Tuple[str, List[Any]]]) -> bool:
        if not self.worker_url or not self.service_token:
            raise AppError(
                code=ErrorCode.INTERNAL_ERROR,
                message="Cloudflare D1 Worker URL or Service Token is not configured",
                status_code=500
            )

        headers = {
            "Authorization": f"Bearer {self.service_token}",
            "Content-Type": "application/json"
        }
        payload = {
            "statements": [{"sql": sql, "params": params or []} for sql, params in statements]
        }

        async with httpx.AsyncClient(timeout=15.0) as client:
            try:
                resp = await client.post(f"{self.worker_url}/batch", json=payload, headers=headers)
            except Exception as ex:
                raise AppError(
                    code=ErrorCode.INTERNAL_ERROR,
                    message=f"D1 Worker batch connection failure: {str(ex)}",
                    status_code=500
                )

        if resp.status_code != 200:
            err = resp.json().get("error", "D1 batch execution failed")
            raise AppError(code=ErrorCode.INTERNAL_ERROR, message=f"D1 batch error: {err}", status_code=500)

        data = resp.json()
        return bool(data.get("success", False))

    def init_schema(self, migrations_dir: str = "../migrations"):
        """Initializes tables in local SQLite mode by applying all migration SQL files in order."""
        if self.mode != "sqlite":
            return

        resolved_dir = os.path.abspath(migrations_dir)
        if not os.path.exists(resolved_dir):
            # Check if relative to app root
            alt_dir = os.path.abspath(os.path.join(os.path.dirname(__file__), "../../../migrations"))
            if os.path.exists(alt_dir):
                resolved_dir = alt_dir

        if not os.path.exists(resolved_dir):
            return

        conn = self._get_sqlite_conn()
        migration_files = sorted([f for f in os.listdir(resolved_dir) if f.endswith(".sql")])
        for f in migration_files:
            file_path = os.path.join(resolved_dir, f)
            with open(file_path, "r", encoding="utf-8") as sql_file:
                sql_content = sql_file.read()
                conn.executescript(sql_content)


db = DatabaseClient()
