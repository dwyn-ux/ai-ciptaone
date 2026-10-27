import json
import uuid
from datetime import datetime, timezone, timedelta
from typing import Any, Dict, List, Optional
from app.core.database import db
from app.core.errors import AppError, ErrorCode
from app.models.schemas import TransactionItem, TransactionListResponse, WalletResponse


class WalletService:
    async def get_wallet(self, user_id: str) -> WalletResponse:
        row = await db.fetch_one(
            "SELECT balance_credits, updated_at FROM wallets WHERE user_id = ?",
            [user_id]
        )
        if not row:
            # Auto-provision wallet if missing
            now_iso = datetime.now(timezone.utc).isoformat()
            await db.execute(
                "INSERT OR IGNORE INTO wallets (user_id, balance_credits, updated_at) VALUES (?, 0, ?)",
                [user_id, now_iso]
            )
            return WalletResponse(balance_credits=0, currency="IDR", updated_at=now_iso)

        return WalletResponse(
            balance_credits=row["balance_credits"],
            currency="IDR",
            updated_at=row["updated_at"]
        )

    async def reserve_credits(self, user_id: str, request_id: str, max_credits: int) -> str:
        """
        Atomically reserves credits for an AI completion request before upstream call.
        Ensures balance_credits >= max_credits, decreases wallet, and creates reservation record.
        """
        if max_credits <= 0:
            return request_id

        reservation_id = f"res_{uuid.uuid4().hex[:16]}"
        now_dt = datetime.now(timezone.utc)
        now_iso = now_dt.isoformat()
        expires_at = (now_dt + timedelta(minutes=15)).isoformat()

        # Step 1: Check balance
        wallet = await self.get_wallet(user_id)
        if wallet.balance_credits < max_credits:
            raise AppError(
                code=ErrorCode.INSUFFICIENT_CREDITS,
                message=f"Saldo kredit tidak mencukupi (Butuh {max_credits} kredit, saldo saat ini {wallet.balance_credits} kredit). Silakan lakukan top-up.",
                status_code=402,
                details={"required_credits": max_credits, "current_balance": wallet.balance_credits}
            )

        # Step 2: Atomic update: deduct balance and insert reservation
        # Note: D1 and SQLite CHECK constraint (balance_credits >= 0) strictly prevents
        # negative balance and rolls back the batch transaction on concurrent race
        statements = [
            (
                """
                UPDATE wallets
                SET balance_credits = balance_credits - ?, updated_at = ?
                WHERE user_id = ?
                """,
                [max_credits, now_iso, user_id]
            ),
            (
                """
                INSERT INTO credit_reservations (id, user_id, request_id, reserved_credits, settled_credits, status, expires_at, created_at, updated_at)
                VALUES (?, ?, ?, ?, 0, 'reserved', ?, ?, ?)
                """,
                [reservation_id, user_id, request_id, max_credits, expires_at, now_iso, now_iso]
            )
        ]

        try:
            await db.execute_batch(statements)
        except Exception as e:
            # If CHECK constraint failed due to concurrent race
            raise AppError(
                code=ErrorCode.INSUFFICIENT_CREDITS,
                message="Gagal mereservasi kredit karena saldo tidak mencukupi atau transaksi bersamaan.",
                status_code=402
            )

        return reservation_id

    async def settle_credits(
        self,
        user_id: str,
        request_id: str,
        actual_billed_credits: int,
        metadata: Optional[Dict[str, Any]] = None
    ) -> int:
        """
        Settles a reserved credit request:
        1. Refunds the unspent portion (reserved - actual) back to the user's wallet.
        2. Marks reservation as 'settled'.
        3. Writes an atomic ledger entry for the net debited credits.
        """
        reservation = await db.fetch_one(
            "SELECT id, reserved_credits, status FROM credit_reservations WHERE request_id = ? AND user_id = ?",
            [request_id, user_id]
        )
        if not reservation:
            raise AppError(
                code=ErrorCode.INVALID_REQUEST,
                message="Reservasi kredit tidak ditemukan.",
                status_code=404
            )

        if reservation["status"] == "settled":
            # Already settled (idempotent return)
            return actual_billed_credits

        reserved_credits = reservation["reserved_credits"]
        # Ensure actual billed doesn't exceed reserved cap
        final_billed = min(actual_billed_credits, reserved_credits)
        refund_amount = max(0, reserved_credits - final_billed)

        now_iso = datetime.now(timezone.utc).isoformat()
        ledger_id = f"led_{uuid.uuid4().hex[:16]}"
        idempotency_key = f"settle_{request_id}"

        # Current wallet to calculate balance_after accurately
        current_wallet = await self.get_wallet(user_id)
        balance_after = current_wallet.balance_credits + refund_amount

        meta_json = json.dumps(metadata or {})

        statements = [
            # 1. Refund the unspent delta back to wallet
            (
                """
                UPDATE wallets
                SET balance_credits = balance_credits + ?, updated_at = ?
                WHERE user_id = ?
                """,
                [refund_amount, now_iso, user_id]
            ),
            # 2. Update reservation status
            (
                """
                UPDATE credit_reservations
                SET settled_credits = ?, status = 'settled', updated_at = ?
                WHERE request_id = ?
                """,
                [final_billed, now_iso, request_id]
            ),
            # 3. Insert ledger entry for the actual debited credits
            (
                """
                INSERT INTO credit_ledger (id, user_id, event_type, amount_credits, balance_after, reference_type, reference_id, idempotency_key, metadata_json, created_at)
                VALUES (?, ?, 'chat_usage', ?, ?, 'request', ?, ?, ?, ?)
                """,
                [ledger_id, user_id, -final_billed, balance_after, request_id, idempotency_key, meta_json, now_iso]
            )
        ]

        await db.execute_batch(statements)
        return final_billed

    async def release_reservation(self, user_id: str, request_id: str) -> None:
        """
        Releases/refunds the entire reservation if upstream request failed.
        """
        reservation = await db.fetch_one(
            "SELECT id, reserved_credits, status FROM credit_reservations WHERE request_id = ? AND user_id = ?",
            [request_id, user_id]
        )
        if not reservation or reservation["status"] != "reserved":
            return

        reserved_credits = reservation["reserved_credits"]
        now_iso = datetime.now(timezone.utc).isoformat()

        statements = [
            (
                """
                UPDATE wallets
                SET balance_credits = balance_credits + ?, updated_at = ?
                WHERE user_id = ?
                """,
                [reserved_credits, now_iso, user_id]
            ),
            (
                """
                UPDATE credit_reservations
                SET status = 'released', updated_at = ?
                WHERE request_id = ?
                """,
                [now_iso, request_id]
            )
        ]
        await db.execute_batch(statements)

    async def credit_wallet_for_topup(
        self,
        user_id: str,
        order_id: str,
        credits_to_grant: int,
        metadata: Optional[Dict[str, Any]] = None
    ) -> int:
        """
        Adds credits to user's wallet when topup is approved.
        Protected by unique idempotency key in credit_ledger.
        """
        idempotency_key = f"topup_{order_id}"
        existing_ledger = await db.fetch_one(
            "SELECT id FROM credit_ledger WHERE idempotency_key = ?",
            [idempotency_key]
        )
        if existing_ledger:
            # Already credited, do not double-credit!
            wallet = await self.get_wallet(user_id)
            return wallet.balance_credits

        now_iso = datetime.now(timezone.utc).isoformat()
        ledger_id = f"led_{uuid.uuid4().hex[:16]}"
        meta_json = json.dumps(metadata or {})

        current_wallet = await self.get_wallet(user_id)
        balance_after = current_wallet.balance_credits + credits_to_grant

        statements = [
            (
                """
                UPDATE wallets
                SET balance_credits = balance_credits + ?, updated_at = ?
                WHERE user_id = ?
                """,
                [credits_to_grant, now_iso, user_id]
            ),
            (
                """
                INSERT INTO credit_ledger (id, user_id, event_type, amount_credits, balance_after, reference_type, reference_id, idempotency_key, metadata_json, created_at)
                VALUES (?, ?, 'topup', ?, ?, 'topup', ?, ?, ?, ?)
                """,
                [ledger_id, user_id, credits_to_grant, balance_after, order_id, idempotency_key, meta_json, now_iso]
            )
        ]

        await db.execute_batch(statements)
        return balance_after

    async def adjust_balance_admin(
        self,
        admin_user_id: str,
        target_user_id: str,
        amount_credits: int,
        reason: str
    ) -> int:
        """
        Admin manual balance adjustment with mandatory audit and ledger entry.
        """
        wallet = await self.get_wallet(target_user_id)
        new_balance = wallet.balance_credits + amount_credits
        if new_balance < 0:
            raise AppError(
                code=ErrorCode.INVALID_REQUEST,
                message=f"Penyesuaian saldo gagal: saldo akhir tidak boleh negatif (Saldo: {wallet.balance_credits}, Pengurangan: {abs(amount_credits)})",
                status_code=400
            )

        now_iso = datetime.now(timezone.utc).isoformat()
        ledger_id = f"led_{uuid.uuid4().hex[:16]}"
        audit_id = f"aud_{uuid.uuid4().hex[:16]}"
        idempotency_key = f"admin_adj_{uuid.uuid4().hex}"

        meta = {"admin_user_id": admin_user_id, "reason": reason}
        meta_json = json.dumps(meta)

        statements = [
            (
                """
                UPDATE wallets
                SET balance_credits = ?, updated_at = ?
                WHERE user_id = ?
                """,
                [new_balance, now_iso, target_user_id]
            ),
            (
                """
                INSERT INTO credit_ledger (id, user_id, event_type, amount_credits, balance_after, reference_type, reference_id, idempotency_key, metadata_json, created_at)
                VALUES (?, ?, 'admin_adjustment', ?, ?, 'admin', ?, ?, ?, ?)
                """,
                [ledger_id, target_user_id, amount_credits, new_balance, admin_user_id, idempotency_key, meta_json, now_iso]
            ),
            (
                """
                INSERT INTO admin_audit_logs (id, admin_user_id, action, target_type, target_id, metadata_json, created_at)
                VALUES (?, ?, 'balance_adjustment', 'user', ?, ?, ?)
                """,
                [audit_id, admin_user_id, target_user_id, meta_json, now_iso]
            )
        ]

        await db.execute_batch(statements)
        return new_balance

    async def list_transactions(self, user_id: str, limit: int = 50, offset: int = 0) -> TransactionListResponse:
        rows = await db.fetch_all(
            """
            SELECT id, event_type, amount_credits, balance_after, reference_type, reference_id, metadata_json, created_at
            FROM credit_ledger
            WHERE user_id = ?
            ORDER BY created_at DESC
            LIMIT ? OFFSET ?
            """,
            [user_id, limit, offset]
        )
        total_row = await db.fetch_one("SELECT COUNT(*) as total FROM credit_ledger WHERE user_id = ?", [user_id])
        total = total_row["total"] if total_row else 0

        items = []
        for r in rows:
            meta = None
            if r["metadata_json"]:
                try:
                    meta = json.loads(r["metadata_json"])
                except Exception:
                    pass
            items.append(
                TransactionItem(
                    id=r["id"],
                    event_type=r["event_type"],
                    amount_credits=r["amount_credits"],
                    balance_after=r["balance_after"],
                    reference_type=r["reference_type"],
                    reference_id=r["reference_id"],
                    metadata=meta,
                    created_at=r["created_at"]
                )
            )

        return TransactionListResponse(items=items, total=total)


wallet_service = WalletService()
