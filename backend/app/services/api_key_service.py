import uuid
from datetime import datetime, timezone
from typing import List, Optional, Tuple
from app.core.database import db
from app.core.errors import AppError, ErrorCode
from app.core.security import generate_api_key, hash_token, secure_compare
from app.models.schemas import ApiKeyCreatedResponse, ApiKeyResponse


class ApiKeyService:
    async def create_api_key(self, user_id: str, name: str) -> ApiKeyCreatedResponse:
        key_id = f"key_{uuid.uuid4().hex[:16]}"
        raw_key, key_prefix, key_hash = generate_api_key()
        now_iso = datetime.now(timezone.utc).isoformat()

        await db.execute(
            """
            INSERT INTO api_keys (id, user_id, name, key_prefix, key_hash, last_used_at, revoked_at, created_at)
            VALUES (?, ?, ?, ?, ?, NULL, NULL, ?)
            """,
            [key_id, user_id, name.strip(), key_prefix, key_hash, now_iso]
        )

        return ApiKeyCreatedResponse(
            id=key_id,
            name=name.strip(),
            key_prefix=key_prefix,
            last_used_at=None,
            created_at=now_iso,
            key=raw_key  # Plaintext key presented only at creation time
        )

    async def list_user_api_keys(self, user_id: str) -> List[ApiKeyResponse]:
        rows = await db.fetch_all(
            """
            SELECT id, name, key_prefix, last_used_at, created_at
            FROM api_keys
            WHERE user_id = ? AND revoked_at IS NULL
            ORDER BY created_at DESC
            """,
            [user_id]
        )
        return [
            ApiKeyResponse(
                id=r["id"],
                name=r["name"],
                key_prefix=r["key_prefix"],
                last_used_at=r["last_used_at"],
                created_at=r["created_at"]
            )
            for r in rows
        ]

    async def revoke_api_key(self, user_id: str, key_id: str) -> bool:
        now_iso = datetime.now(timezone.utc).isoformat()
        rowcount = await db.execute(
            """
            UPDATE api_keys
            SET revoked_at = ?
            WHERE id = ? AND user_id = ? AND revoked_at IS NULL
            """,
            [now_iso, key_id, user_id]
        )
        if rowcount == 0:
            raise AppError(
                code=ErrorCode.NOT_FOUND,
                message="API key tidak ditemukan atau sudah dicabut.",
                status_code=404
            )
        return True

    async def validate_api_key(self, raw_key: str) -> Tuple[str, str]:
        """
        Validates an incoming API key string.
        Returns (user_id, api_key_id) if valid and not revoked.
        Raises AppError(UNAUTHORIZED) if invalid.
        """
        if not raw_key or not raw_key.startswith("sk-live-"):
            raise AppError(
                code=ErrorCode.UNAUTHORIZED,
                message="API key tidak valid atau format salah.",
                status_code=401
            )

        k_hash = hash_token(raw_key)
        now_iso = datetime.now(timezone.utc).isoformat()

        row = await db.fetch_one(
            """
            SELECT k.id, k.user_id, k.key_hash, u.status as user_status
            FROM api_keys k
            JOIN users u ON k.user_id = u.id
            WHERE k.key_hash = ? AND k.revoked_at IS NULL
            """,
            [k_hash]
        )

        if not row:
            raise AppError(
                code=ErrorCode.UNAUTHORIZED,
                message="API key tidak valid atau telah dicabut.",
                status_code=401
            )

        if row["user_status"] != "active":
            raise AppError(
                code=ErrorCode.FORBIDDEN,
                message="Akun pemilik API key tidak aktif.",
                status_code=403
            )

        # Constant-time verify as extra defense
        if not secure_compare(row["key_hash"], k_hash):
            raise AppError(
                code=ErrorCode.UNAUTHORIZED,
                message="Verifikasi API key gagal.",
                status_code=401
            )

        # Update last_used_at
        await db.execute(
            "UPDATE api_keys SET last_used_at = ? WHERE id = ?",
            [now_iso, row["id"]]
        )

        return row["user_id"], row["id"]


api_key_service = ApiKeyService()
