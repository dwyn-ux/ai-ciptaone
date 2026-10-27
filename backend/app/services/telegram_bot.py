import json
import logging
from typing import Any, Dict, Optional
import httpx
from app.config import settings
from app.core.errors import AppError, ErrorCode

logger = logging.getLogger(__name__)


class TelegramBotService:
    def __init__(self):
        self.bot_token = settings.TELEGRAM_BOT_TOKEN
        self.base_url = f"https://api.telegram.org/bot{self.bot_token}" if self.bot_token else None

    async def notify_admin_new_topup(
        self,
        order_id: str,
        user_email: str,
        amount_minor: int,
        credits_to_grant: int,
        payment_method: str,
        has_proof: bool = False,
    ) -> None:
        """
        Sends invoice notification to all configured admin Telegram IDs.
        Provides inline action buttons: '✅ Setujui' and '❌ Tolak'.
        """
        if not self.bot_token or not settings.telegram_admin_id_list:
            logger.info("Telegram bot not configured; skipping admin notification.")
            return

        method_label = "Transfer Bank" if payment_method == "manual_transfer" else "QRIS Manual"
        text = (
            f"🔔 *PERMINTAAN TOP-UP BARU*\n\n"
            f"• *Order ID:* `{order_id}`\n"
            f"• *Pengguna:* `{user_email}`\n"
            f"• *Nominal:* Rp{amount_minor:,}\n"
            f"• *Kredit:* {credits_to_grant:,} kredit\n"
            f"• *Metode:* {method_label}\n"
            f"• *Bukti Transfer:* {'Sudah diunggah (periksa mutasi resmi!)' if has_proof else 'Belum diunggah'}\n\n"
            f"⚠️ *Perhatian:* Jangan setujui hanya karena melihat screenshot. Pastikan dana benar-benar masuk pada mutasi rekening resmi."
        )

        reply_markup = {
            "inline_keyboard": [
                [
                    {"text": "✅ Setujui", "callback_data": f"topup:approve:{order_id}"},
                    {"text": "❌ Tolak", "callback_data": f"topup:reject:{order_id}"},
                ]
            ]
        }

        async with httpx.AsyncClient(timeout=10.0) as client:
            for admin_id in settings.telegram_admin_id_list:
                try:
                    await client.post(
                        f"{self.base_url}/sendMessage",
                        json={
                            "chat_id": admin_id,
                            "text": text,
                            "parse_mode": "Markdown",
                            "reply_markup": reply_markup,
                        },
                    )
                except Exception as ex:
                    logger.error(f"Failed to send Telegram message to {admin_id}: {ex}")

    async def handle_callback_query(
        self,
        callback_query: Dict[str, Any],
        topup_service_fn,
    ) -> Dict[str, Any]:
        """
        Handles button click from Telegram bot:
        1. Validates sender ID against allowlist.
        2. Parses callback data (topup:approve:<order_id> or topup:reject:<order_id>).
        3. Calls review function with idempotency.
        4. Answers callback query and updates Telegram message text.
        """
        callback_id = callback_query.get("id")
        from_user = callback_query.get("from", {})
        telegram_user_id = from_user.get("id")
        data = callback_query.get("data", "")
        message = callback_query.get("message", {})
        chat_id = message.get("chat", {}).get("id")
        message_id = message.get("message_id")

        # 1. Allowlist Check
        if telegram_user_id not in settings.telegram_admin_id_list:
            await self._answer_callback(callback_id, "⛔ Akses ditolak: Anda bukan admin terdaftar.", alert=True)
            return {"status": "forbidden"}

        # 2. Parse callback data
        parts = data.split(":")
        if len(parts) != 3 or parts[0] != "topup":
            await self._answer_callback(callback_id, "Format callback tidak valid.", alert=False)
            return {"status": "invalid_format"}

        action, order_id = parts[1], parts[2]
        if action not in ("approve", "reject"):
            await self._answer_callback(callback_id, "Aksi tidak dikenal.", alert=False)
            return {"status": "unknown_action"}

        # 3. Call Topup Service
        admin_identifier = f"tg_{telegram_user_id}_{from_user.get('username', 'admin')}"
        status_to_set = "paid" if action == "approve" else "rejected"
        rejection_reason = "Ditolak oleh admin via Telegram" if action == "reject" else None

        try:
            result = await topup_service_fn(
                order_id=order_id,
                action=status_to_set,
                admin_user_id=admin_identifier,
                rejection_reason=rejection_reason,
            )
            resp_text = (
                f"✅ Order {order_id} berhasil DISETUJUI dan saldo dikreditkan."
                if action == "approve"
                else f"❌ Order {order_id} berhasil DITOLAK."
            )
            await self._answer_callback(callback_id, resp_text, alert=True)

            # Update original message text
            if chat_id and message_id:
                orig_text = message.get("text", "")
                decision_badge = (
                    f"\n\n🏁 *STATUS:* DISETUJUI oleh @{from_user.get('username', telegram_user_id)}"
                    if action == "approve"
                    else f"\n\n🏁 *STATUS:* DITOLAK oleh @{from_user.get('username', telegram_user_id)}"
                )
                await self._edit_message_text(chat_id, message_id, orig_text + decision_badge)

            return {"status": "success", "result": result}

        except AppError as ae:
            await self._answer_callback(callback_id, f"Gagal: {ae.message}", alert=True)
            return {"status": "error", "error": ae.message}
        except Exception as ex:
            await self._answer_callback(callback_id, f"Kesalahan sistem: {str(ex)}", alert=True)
            return {"status": "error", "error": str(ex)}

    async def _answer_callback(self, callback_id: str, text: str, alert: bool = False):
        if not self.base_url:
            return
        async with httpx.AsyncClient(timeout=5.0) as client:
            try:
                await client.post(
                    f"{self.base_url}/answerCallbackQuery",
                    json={"callback_query_id": callback_id, "text": text, "show_alert": alert},
                )
            except Exception as e:
                logger.error(f"Error answering callback query: {e}")

    async def _edit_message_text(self, chat_id: int, message_id: int, text: str):
        if not self.base_url:
            return
        async with httpx.AsyncClient(timeout=5.0) as client:
            try:
                await client.post(
                    f"{self.base_url}/editMessageText",
                    json={
                        "chat_id": chat_id,
                        "message_id": message_id,
                        "text": text,
                        "parse_mode": "Markdown",
                    },
                )
            except Exception as e:
                logger.error(f"Error editing message text: {e}")


telegram_bot_service = TelegramBotService()
