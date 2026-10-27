import base64
import hashlib
import hmac
import json
from typing import Any, Dict, Optional
import httpx
from app.config import settings
from app.core.errors import AppError, ErrorCode
from app.services.payment_adapters.base import BasePaymentAdapter


class DanaPaymentAdapter(BasePaymentAdapter):
    """
    Adapter for Official DANA Dynamic QRIS / Merchant API.
    
    IMPORTANT: Per PRD Section 7 & 14, this adapter is prepared for the official
    DANA Dynamic QRIS integration. It remains deactivated until the business merchant
    account, official API access, client credentials, and webhook endpoints are approved by DANA.
    """

    def __init__(self):
        self.enabled = settings.DANA_ENABLED
        self.env = settings.DANA_ENV
        self.client_id = settings.DANA_CLIENT_ID
        self.merchant_id = settings.DANA_MERCHANT_ID
        self.client_secret = settings.DANA_CLIENT_SECRET
        self.public_key = settings.DANA_PUBLIC_KEY
        self.private_key = settings.DANA_PRIVATE_KEY
        self.base_url = (
            "https://api.dana.id" if self.env == "production" else "https://api-sandbox.dana.id"
        )

    def _assert_configured(self):
        if not self.enabled or not self.client_id or not self.client_secret:
            raise AppError(
                code=ErrorCode.INVALID_REQUEST,
                message=(
                    "Integrasi DANA QRIS Dinamis belum aktif: menunggu persetujuan merchant resmi DANA Bisnis, "
                    "verifikasi akun, dan penerbitan kredensial API. Silakan gunakan metode Transfer Bank / QRIS Manual."
                ),
                status_code=400,
            )

    async def create_invoice(
        self,
        order_id: str,
        amount_minor: int,
        currency: str,
        user_id: str,
        user_email: str,
    ) -> Dict[str, Any]:
        self._assert_configured()

        payload = {
            "partnerReferenceNo": order_id,
            "amount": {
                "value": f"{amount_minor}.00",
                "currency": currency,
            },
            "merchantId": self.merchant_id,
            "validUpTo": (settings.TOPUP_EXPIRY_MINUTES * 60),
        }

        # Example signature calculation for DANA Open API / SNAP standard
        timestamp = "2026-10-26T00:00:00+07:00"
        signature = self._generate_signature(payload, timestamp)

        headers = {
            "Content-Type": "application/json",
            "X-CLIENT-KEY": self.client_id,
            "X-TIMESTAMP": timestamp,
            "X-SIGNATURE": signature,
            "X-PARTNER-ID": self.merchant_id,
        }

        async with httpx.AsyncClient(timeout=30.0) as client:
            try:
                resp = await client.post(
                    f"{self.base_url}/v1.0/qr/qr-mpm-generate",
                    json=payload,
                    headers=headers,
                )
            except Exception as e:
                raise AppError(
                    code=ErrorCode.PROVIDER_ERROR,
                    message=f"Gagal menghubungi gateway DANA: {str(e)}",
                    status_code=502,
                )

        if resp.status_code != 200:
            raise AppError(
                code=ErrorCode.PROVIDER_ERROR,
                message="DANA API merespons dengan kesalahan.",
                status_code=502,
                details={"status": resp.status_code, "body": resp.text},
            )

        data = resp.json()
        qr_content = data.get("qrContent", "")
        checkout_url = data.get("checkoutUrl", None)

        return {
            "checkout_url": checkout_url,
            "provider_reference": data.get("referenceNo", order_id),
            "instructions": {
                "method": "dana_qris_dynamic",
                "title": "DANA QRIS Dinamis",
                "order_id": order_id,
                "amount": amount_minor,
                "currency": currency,
                "qr_content": qr_content,
                "instruction": "Pindai kode QRIS dinamis menggunakan aplikasi DANA atau e-wallet lainnya.",
            },
        }

    async def verify_webhook(self, headers: Dict[str, str], raw_body: bytes) -> Dict[str, Any]:
        self._assert_configured()

        received_signature = headers.get("x-signature") or headers.get("X-Signature")
        timestamp = headers.get("x-timestamp") or headers.get("X-Timestamp")
        if not received_signature or not timestamp:
            raise AppError(
                code=ErrorCode.PAYMENT_VERIFICATION_FAILED,
                message="Header signature DANA tidak lengkap.",
                status_code=400,
            )

        # Verify HMAC signature against client_secret
        expected_sig = self._generate_signature_raw(raw_body, timestamp)
        if not hmac.compare_digest(received_signature, expected_sig):
            raise AppError(
                code=ErrorCode.PAYMENT_VERIFICATION_FAILED,
                message="Verifikasi tanda tangan webhook DANA gagal.",
                status_code=401,
            )

        data = json.loads(raw_body.decode("utf-8"))
        return {
            "order_id": data.get("partnerReferenceNo"),
            "amount_minor": int(float(data.get("amount", {}).get("value", "0"))),
            "currency": data.get("amount", {}).get("currency", "IDR"),
            "status": "paid" if data.get("latestTransactionStatus") == "00" else "failed",
            "provider_reference": data.get("referenceNo"),
            "raw": data,
        }

    def _generate_signature(self, payload: Dict[str, Any], timestamp: str) -> str:
        body_str = json.dumps(payload, separators=(",", ":"))
        return self._generate_signature_raw(body_str.encode("utf-8"), timestamp)

    def _generate_signature_raw(self, body_bytes: bytes, timestamp: str) -> str:
        digest = hashlib.sha256(body_bytes).hexdigest()
        string_to_sign = f"POST|/v1.0/qr/qr-mpm-generate|{digest}|{timestamp}"
        signature = hmac.new(
            self.client_secret.encode("utf-8"),
            string_to_sign.encode("utf-8"),
            hashlib.sha256,
        ).digest()
        return base64.b64encode(signature).decode("utf-8")
