import json
import uuid
from datetime import datetime, timezone, timedelta
from typing import Optional, Tuple
from app.config import settings
from app.core.database import db
from app.core.errors import AppError, ErrorCode
from app.core.security import (
    hash_password,
    verify_password,
    normalize_email,
    generate_session_token,
    hash_token,
)
from app.models.schemas import UserResponse


class AuthService:
    async def register(self, email: str, password: str) -> UserResponse:
        norm_email = normalize_email(email)
        now_iso = datetime.now(timezone.utc).isoformat()

        # Check if email is already registered
        existing = await db.fetch_one(
            "SELECT id FROM users WHERE email_normalized = ?",
            [norm_email]
        )
        if existing:
            # PRD: Do not leak whether email is already registered to prevent enumeration
            raise AppError(
                code=ErrorCode.INVALID_REQUEST,
                message="Pendaftaran tidak dapat diproses. Pastikan format email benar atau gunakan akun yang ada.",
                status_code=400
            )

        user_id = f"usr_{uuid.uuid4().hex[:16]}"
        pwd_hash = hash_password(password)

        # Batch insert: create user + create initial wallet atomically
        statements = [
            (
                """
                INSERT INTO users (id, email_normalized, password_hash, email_verified_at, status, role, created_at, updated_at)
                VALUES (?, ?, ?, NULL, 'active', 'user', ?, ?)
                """,
                [user_id, norm_email, pwd_hash, now_iso, now_iso]
            ),
            (
                """
                INSERT INTO wallets (user_id, balance_credits, updated_at)
                VALUES (?, 0, ?)
                """,
                [user_id, now_iso]
            ),
        ]
        await db.execute_batch(statements)

        return UserResponse(
            id=user_id,
            email=norm_email,
            email_verified=False,
            status="active",
            role="user",
            created_at=now_iso
        )

    async def login(self, email: str, password: str) -> Tuple[UserResponse, str]:
        """
        Authenticates user, creates a new session with session rotation.
        Returns: (UserResponse, raw_session_token)
        """
        norm_email = normalize_email(email)
        user_row = await db.fetch_one(
            "SELECT id, email_normalized, password_hash, email_verified_at, status, role, created_at FROM users WHERE email_normalized = ?",
            [norm_email]
        )

        if not user_row or not verify_password(password, user_row["password_hash"]):
            raise AppError(
                code=ErrorCode.UNAUTHORIZED,
                message="Email atau kata sandi tidak valid.",
                status_code=401
            )

        if user_row["status"] != "active":
            raise AppError(
                code=ErrorCode.FORBIDDEN,
                message="Akun Anda sedang dinonaktifkan atau disuspend.",
                status_code=403
            )

        user_id = user_row["id"]
        raw_token, token_hash = generate_session_token()
        now_dt = datetime.now(timezone.utc)
        now_iso = now_dt.isoformat()
        expires_at = (now_dt + timedelta(seconds=settings.SESSION_LIFETIME_SECONDS)).isoformat()
        session_id = f"ses_{uuid.uuid4().hex[:16]}"

        # Save session in database
        await db.execute(
            """
            INSERT INTO sessions (id, user_id, token_hash, expires_at, created_at, last_seen_at)
            VALUES (?, ?, ?, ?, ?, ?)
            """,
            [session_id, user_id, token_hash, expires_at, now_iso, now_iso]
        )

        user_resp = UserResponse(
            id=user_id,
            email=user_row["email_normalized"],
            email_verified=bool(user_row["email_verified_at"]),
            status=user_row["status"],
            role=user_row["role"],
            created_at=user_row["created_at"]
        )
        return user_resp, raw_token

    async def get_user_from_session(self, raw_token: str) -> Optional[UserResponse]:
        """Resolves active user from raw session token."""
        if not raw_token:
            return None

        t_hash = hash_token(raw_token)
        now_iso = datetime.now(timezone.utc).isoformat()

        row = await db.fetch_one(
            """
            SELECT u.id, u.email_normalized, u.email_verified_at, u.status, u.role, u.created_at, s.id as session_id
            FROM sessions s
            JOIN users u ON s.user_id = u.id
            WHERE s.token_hash = ? AND s.expires_at > ? AND u.status = 'active'
            """,
            [t_hash, now_iso]
        )
        if not row:
            return None

        # Update last seen timestamp
        await db.execute(
            "UPDATE sessions SET last_seen_at = ? WHERE id = ?",
            [now_iso, row["session_id"]]
        )

        return UserResponse(
            id=row["id"],
            email=row["email_normalized"],
            email_verified=bool(row["email_verified_at"]),
            status=row["status"],
            role=row["role"],
            created_at=row["created_at"]
        )

    async def logout(self, raw_token: str) -> None:
        """Revokes the current session."""
        if not raw_token:
            return
        t_hash = hash_token(raw_token)
        await db.execute("DELETE FROM sessions WHERE token_hash = ?", [t_hash])


auth_service = AuthService()
