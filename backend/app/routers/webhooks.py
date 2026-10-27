import hashlib
import json
import uuid
from datetime import datetime, timezone
from typing import Any, Dict
from fastapi import APIRouter, Header, Request, status
from fastapi.responses import JSONResponse
from app.config import settings
from app.core.database import db
from app.core.errors import AppError, ErrorCode
from app.services.payment_adapters.dana import DanaPaymentAdapter
from app.services.telegram_bot import telegram_bot_service
from app.services.topup_service import topup_service

router = APIRouter(prefix="/api/v1/webhooks", tags=["Webhooks"])


@router.post("/telegram")
async def telegram_webhook(
    request: Request,
    x_telegram_bot_api_secret_token: str = Header(default=None),
):
    """
    Receives Telegram Bot updates, specifically callback queries from inline buttons.
    """
    if settings.TELEGRAM_WEBHOOK_SECRET and x_telegram_bot_api_secret_token != settings.TELEGRAM_WEBHOOK_SECRET:
        raise AppError(code=ErrorCode.UNAUTHORIZED, message="Invalid Telegram secret token", status_code=401)

    payload = await request.json()

    if "callback_query" in payload:
        cb = payload["callback_query"]
        await telegram_bot_service.handle_callback_query(
            callback_query=cb,
            topup_service_fn=topup_service.review_topup,
        )

    return {"ok": True}


@router.post("/{gateway}")
async def payment_gateway_webhook(gateway: str, request: Request):
    """
    Generic webhook receiver for payment gateways (e.g. DANA dynamic QRIS).
    Enforces payload deduplication, signature verification, and atomic balance crediting.
    """
    raw_body = await request.body()
    headers = dict(request.headers)
    payload_hash = hashlib.sha256(raw_body).hexdigest()
    now_iso = datetime.now(timezone.utc).isoformat()
    webhook_id = f"wh_{uuid.uuid4().hex[:16]}"

    # Event Deduplication via payload_hash & gateway
    existing = await db.fetch_one(
        "SELECT id, processing_status FROM payment_webhook_events WHERE gateway = ? AND payload_hash = ?",
        [gateway, payload_hash],
    )
    if existing:
        return {"status": "ignored", "message": "Duplicate event payload already recorded"}

    # Record event in payment_webhook_events table
    await db.execute(
        """
        INSERT INTO payment_webhook_events (id, gateway, event_id, payload_hash, processing_status, received_at, processed_at, error_code)
        VALUES (?, ?, ?, ?, 'received', ?, NULL, NULL)
        """,
        [webhook_id, gateway, payload_hash[:16], payload_hash, now_iso]
    )

    if gateway == "dana":
        adapter = DanaPaymentAdapter()
        try:
            verified_data = await adapter.verify_webhook(headers, raw_body)
            order_id = verified_data["order_id"]
            if verified_data.get("status") == "paid":
                await topup_service.review_topup(
                    order_id=order_id,
                    action="paid",
                    admin_user_id="gateway:dana:webhook",
                )
                await db.execute(
                    "UPDATE payment_webhook_events SET processing_status = 'processed', processed_at = ? WHERE id = ?",
                    [now_iso, webhook_id]
                )
        except Exception as ex:
            await db.execute(
                "UPDATE payment_webhook_events SET processing_status = 'failed', error_code = ? WHERE id = ?",
                [str(ex)[:255], webhook_id]
            )
            raise ex
    else:
        raise AppError(code=ErrorCode.INVALID_REQUEST, message=f"Gateway '{gateway}' tidak didukung.", status_code=400)

    return {"status": "success", "message": "Webhook processed successfully"}
