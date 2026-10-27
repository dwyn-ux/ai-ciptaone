from typing import Any, Dict
from app.config import settings
from app.services.payment_adapters.base import BasePaymentAdapter


class ManualPaymentAdapter(BasePaymentAdapter):
    """
    Adapter for Manual Top-Up via Bank Transfer or Official Static QRIS.
    Does not automate bank verification; relies on admin review via Telegram Bot
    against official bank mutations.
    """

    def __init__(self, method_type: str = "manual_transfer"):
        self.method_type = method_type

    async def create_invoice(
        self,
        order_id: str,
        amount_minor: int,
        currency: str,
        user_id: str,
        user_email: str,
    ) -> Dict[str, Any]:
        if self.method_type == "manual_qris":
            instructions = {
                "method": "manual_qris",
                "title": "Pembayaran QRIS",
                "instruction": f"Silakan pindai QRIS resmi dan bayar tepat Rp{amount_minor:,}. Cantumkan nomor pesanan {order_id} pada catatan jika memungkinkan.",
                "qris_image_url": settings.MANUAL_QRIS_IMAGE_URL or None,
                "order_id": order_id,
                "amount": amount_minor,
                "currency": currency,
            }
        else:
            instructions = {
                "method": "manual_transfer",
                "title": "Transfer Bank Manual",
                "bank_name": settings.MANUAL_BANK_NAME,
                "account_number": settings.MANUAL_ACCOUNT_NO,
                "account_holder": settings.MANUAL_ACCOUNT_HOLDER,
                "order_id": order_id,
                "amount": amount_minor,
                "currency": currency,
                "instruction": f"Transfer tepat sebesar Rp{amount_minor:,} ke rekening di atas. Cantumkan nomor referensi {order_id} pada berita transfer.",
            }

        return {
            "checkout_url": None,
            "provider_reference": order_id,
            "instructions": instructions,
        }

    async def verify_webhook(self, headers: Dict[str, str], raw_body: bytes) -> Dict[str, Any]:
        # Manual top-up does not receive external gateway webhooks;
        # status updates occur strictly via backend/Telegram admin approval.
        raise NotImplementedError("Manual payment adapter does not process gateway webhooks")
