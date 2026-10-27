import json
import os
import uuid
from datetime import datetime, timezone, timedelta
from typing import Any, Dict, List, Optional
import aiofiles
from app.config import settings
from app.core.database import db
from app.core.errors import AppError, ErrorCode
from app.models.schemas import TopupResponse
from app.services.payment_adapters.dana import DanaPaymentAdapter
from app.services.payment_adapters.manual import ManualPaymentAdapter
from app.services.telegram_bot import telegram_bot_service
from app.services.wallet_service import wallet_service


class TopupService:
    def __init__(self):
        self.manual_transfer_adapter = ManualPaymentAdapter("manual_transfer")
        self.manual_qris_adapter = ManualPaymentAdapter("manual_qris")
        self.dana_adapter = DanaPaymentAdapter()

    def _get_adapter(self, payment_method: str):
        if payment_method == "manual_transfer":
            return self.manual_transfer_adapter
        elif payment_method == "manual_qris":
            return self.manual_qris_adapter
        elif payment_method == "dana_qris_dynamic":
            return self.dana_adapter
        else:
            raise AppError(
                code=ErrorCode.INVALID_REQUEST,
                message=f"Metode pembayaran '{payment_method}' tidak valid.",
                status_code=400,
            )

    async def create_topup(
        self,
        user_id: str,
        user_email: str,
        amount_minor: int,
        payment_method: str = "manual_transfer",
    ) -> TopupResponse:
        """
        Creates a new pending topup invoice.
        Server calculates credits_to_grant to prevent client tampering.
        """
        if amount_minor < 10000:
            raise AppError(
                code=ErrorCode.INVALID_REQUEST,
                message="Nominal top-up minimum adalah Rp10.000.",
                status_code=400,
            )

        credits_to_grant = amount_minor * settings.CREDIT_PER_RUPIAH
        topup_id = f"top_{uuid.uuid4().hex[:16]}"
        now_dt = datetime.now(timezone.utc)
        date_str = now_dt.strftime("%Y%m%d")
        order_id = f"INV-{date_str}-{uuid.uuid4().hex[:6].upper()}"

        expires_dt = now_dt + timedelta(minutes=settings.TOPUP_EXPIRY_MINUTES)
        now_iso = now_dt.isoformat()
        expires_iso = expires_dt.isoformat()
        idempotency_key = f"topup_order_{order_id}"

        adapter = self._get_adapter(payment_method)
        invoice_data = await adapter.create_invoice(
            order_id=order_id,
            amount_minor=amount_minor,
            currency="IDR",
            user_id=user_id,
            user_email=user_email,
        )

        checkout_url = invoice_data.get("checkout_url")
        provider_ref = invoice_data.get("provider_reference", order_id)
        instructions = invoice_data.get("instructions", {})

        await db.execute(
            """
            INSERT INTO topups (
                id, user_id, order_id, payment_provider, payment_method,
                provider_reference, amount_minor, currency, credits_to_grant,
                status, proof_file_key, reviewed_by, reviewed_at, rejection_reason,
                idempotency_key, paid_at, expires_at, created_at, updated_at
            )
            VALUES (?, ?, ?, ?, ?, ?, ?, 'IDR', ?, 'pending', NULL, NULL, NULL, NULL, ?, NULL, ?, ?, ?)
            """,
            [
                topup_id, user_id, order_id, payment_method, payment_method,
                provider_ref, amount_minor, credits_to_grant,
                idempotency_key, expires_iso, now_iso, now_iso
            ]
        )

        # Notify admin on Telegram for visibility
        try:
            await telegram_bot_service.notify_admin_new_topup(
                order_id=order_id,
                user_email=user_email,
                amount_minor=amount_minor,
                credits_to_grant=credits_to_grant,
                payment_method=payment_method,
                has_proof=False,
            )
        except Exception:
            pass

        return TopupResponse(
            topup_id=topup_id,
            order_id=order_id,
            amount_minor=amount_minor,
            currency="IDR",
            credits_to_grant=credits_to_grant,
            payment_method=payment_method,
            status="pending",
            expires_at=expires_iso,
            checkout_url=checkout_url,
            instructions=instructions,
        )

    async def get_topup_by_id_or_order(self, identifier: str, user_id: Optional[str] = None) -> Dict[str, Any]:
        """Fetches topup by either id or order_id."""
        query = "SELECT * FROM topups WHERE (id = ? OR order_id = ?)"
        params = [identifier, identifier]
        if user_id:
            query += " AND user_id = ?"
            params.append(user_id)

        row = await db.fetch_one(query, params)
        if not row:
            raise AppError(code=ErrorCode.NOT_FOUND, message="Invoice top-up tidak ditemukan.", status_code=404)
        return row

    async def upload_proof(
        self,
        order_id: str,
        user_id: str,
        filename: str,
        content_type: str,
        file_bytes: bytes,
    ) -> Dict[str, Any]:
        """
        Stores proof in private local storage and updates topup status to 'under_review'.
        Validates content type, file size, and extension to prevent malicious uploads.
        """
        topup = await self.get_topup_by_id_or_order(order_id, user_id=user_id)
        if topup["status"] not in ("pending", "under_review"):
            raise AppError(
                code=ErrorCode.INVALID_REQUEST,
                message=f"Tidak dapat mengunggah bukti: status invoice saat ini adalah '{topup['status']}'.",
                status_code=400,
            )

        # Check file size
        if len(file_bytes) > settings.MAX_PROOF_SIZE_BYTES:
            raise AppError(
                code=ErrorCode.INVALID_REQUEST,
                message=f"Ukuran file bukti melebihi batas {settings.MAX_PROOF_SIZE_BYTES // (1024*1024)}MB.",
                status_code=400,
            )

        # Validate MIME
        allowed_mimes = ["image/jpeg", "image/png", "image/webp"]
        if content_type.lower() not in allowed_mimes:
            raise AppError(
                code=ErrorCode.INVALID_REQUEST,
                message="Tipe file bukti harus berformat JPEG, PNG, atau WEBP.",
                status_code=400,
            )

        # Determine safe extension
        ext = ".jpg"
        if "png" in content_type:
            ext = ".png"
        elif "webp" in content_type:
            ext = ".webp"

        proof_key = f"proof_{uuid.uuid4().hex}{ext}"
        os.makedirs(settings.PROOF_UPLOAD_DIR, exist_ok=True)
        dest_path = os.path.join(settings.PROOF_UPLOAD_DIR, proof_key)

        async with aiofiles.open(dest_path, "wb") as f:
            await f.write(file_bytes)

        now_iso = datetime.now(timezone.utc).isoformat()
        await db.execute(
            """
            UPDATE topups
            SET proof_file_key = ?, status = 'under_review', updated_at = ?
            WHERE order_id = ?
            """,
            [proof_key, now_iso, order_id]
        )

        # Re-notify admins on Telegram that proof has been uploaded
        user = await db.fetch_one("SELECT email_normalized FROM users WHERE id = ?", [user_id])
        user_email = user["email_normalized"] if user else "user"
        try:
            await telegram_bot_service.notify_admin_new_topup(
                order_id=order_id,
                user_email=user_email,
                amount_minor=topup["amount_minor"],
                credits_to_grant=topup["credits_to_grant"],
                payment_method=topup["payment_method"],
                has_proof=True,
            )
        except Exception:
            pass

        return {
            "success": True,
            "status": "under_review",
            "message": "Bukti pembayaran berhasil diunggah. Admin akan memverifikasi mutasi rekening.",
        }

    async def review_topup(
        self,
        order_id: str,
        action: str,  # 'paid' or 'rejected'
        admin_user_id: str,
        rejection_reason: Optional[str] = None,
    ) -> Dict[str, Any]:
        """
        Admin reviews top-up transaction.
        Applies atomic credit grant with idempotency.
        """
        topup = await self.get_topup_by_id_or_order(order_id)
        current_status = topup["status"]

        if current_status == "paid":
            # Idempotent response: already paid
            return {"status": "paid", "message": "Top-up sudah disetujui sebelumnya."}

        if current_status not in ("pending", "under_review"):
            raise AppError(
                code=ErrorCode.INVALID_REQUEST,
                message=f"Invoice dengan status '{current_status}' tidak dapat ditinjau ulang.",
                status_code=400,
            )

        now_iso = datetime.now(timezone.utc).isoformat()
        audit_id = f"aud_{uuid.uuid4().hex[:16]}"

        if action == "paid":
            # 1. Update topup record
            await db.execute(
                """
                UPDATE topups
                SET status = 'paid', paid_at = ?, reviewed_by = ?, reviewed_at = ?, updated_at = ?
                WHERE order_id = ? AND status != 'paid'
                """,
                [now_iso, admin_user_id, now_iso, now_iso, order_id]
            )

            # 2. Grant credits atomically to wallet with ledger entry
            new_balance = await wallet_service.credit_wallet_for_topup(
                user_id=topup["user_id"],
                order_id=order_id,
                credits_to_grant=topup["credits_to_grant"],
                metadata={
                    "order_id": order_id,
                    "amount_minor": topup["amount_minor"],
                    "payment_method": topup["payment_method"],
                    "reviewed_by": admin_user_id,
                }
            )

            # 3. Write admin audit log
            audit_meta = json.dumps({
                "order_id": order_id,
                "amount_minor": topup["amount_minor"],
                "credits_to_grant": topup["credits_to_grant"],
                "action": "approve"
            })
            await db.execute(
                """
                INSERT INTO admin_audit_logs (id, admin_user_id, action, target_type, target_id, metadata_json, created_at)
                VALUES (?, ?, 'approve_topup', 'topup', ?, ?, ?)
                """,
                [audit_id, admin_user_id, order_id, audit_meta, now_iso]
            )

            return {
                "status": "paid",
                "message": f"Top-up berhasil disetujui. Saldo pengguna kini {new_balance} kredit.",
                "new_balance": new_balance,
            }

        elif action == "rejected":
            reason = rejection_reason or "Pembayaran tidak ditemukan dalam mutasi rekening resmi."
            await db.execute(
                """
                UPDATE topups
                SET status = 'rejected', rejection_reason = ?, reviewed_by = ?, reviewed_at = ?, updated_at = ?
                WHERE order_id = ?
                """,
                [reason, admin_user_id, now_iso, now_iso, order_id]
            )

            audit_meta = json.dumps({
                "order_id": order_id,
                "reason": reason,
                "action": "reject"
            })
            await db.execute(
                """
                INSERT INTO admin_audit_logs (id, admin_user_id, action, target_type, target_id, metadata_json, created_at)
                VALUES (?, ?, 'reject_topup', 'topup', ?, ?, ?)
                """,
                [audit_id, admin_user_id, order_id, audit_meta, now_iso]
            )

            return {
                "status": "rejected",
                "message": f"Top-up berhasil ditolak. Alasan: {reason}",
            }
        else:
            raise AppError(code=ErrorCode.INVALID_REQUEST, message="Aksi review tidak valid.", status_code=400)

    async def list_user_topups(self, user_id: str, limit: int = 20) -> List[Dict[str, Any]]:
        rows = await db.fetch_all(
            """
            SELECT id, order_id, amount_minor, currency, credits_to_grant, payment_method,
                   status, proof_file_key, rejection_reason, expires_at, created_at, paid_at
            FROM topups
            WHERE user_id = ?
            ORDER BY created_at DESC
            LIMIT ?
            """,
            [user_id, limit]
        )
        return rows


topup_service = TopupService()
