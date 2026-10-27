import pytest
from app.config import settings
from app.core.errors import AppError
from app.services.auth_service import auth_service
from app.services.telegram_bot import telegram_bot_service
from app.services.topup_service import topup_service
from app.services.wallet_service import wallet_service


@pytest.mark.asyncio
async def test_topup_lifecycle_and_telegram():
    # Setup test user and configure admin ID
    user = await auth_service.register(email="topup_tester@ciptaone.com", password="TopupPassword123!")
    settings.TELEGRAM_ADMIN_IDS = "999888111,12345678"

    # 1. Create Top-up Invoice
    topup_resp = await topup_service.create_topup(
        user_id=user.id,
        user_email=user.email,
        amount_minor=50000,
        payment_method="manual_transfer"
    )
    assert topup_resp.order_id.startswith("INV-")
    assert topup_resp.credits_to_grant == 50000
    assert topup_resp.status == "pending"
    assert "instructions" in topup_resp.model_dump()

    # 2. Upload Proof (Valid JPEG image bytes)
    fake_jpeg = b"\xff\xd8\xff\xe0\x00\x10JFIF\x00\x01\x01\x00\x00\x01\x00\x01\x00\x00\xff\xdb"
    proof_result = await topup_service.upload_proof(
        order_id=topup_resp.order_id,
        user_id=user.id,
        filename="bukti_transfer.jpg",
        content_type="image/jpeg",
        file_bytes=fake_jpeg
    )
    assert proof_result["success"] is True
    assert proof_result["status"] == "under_review"

    # 3. Invalid proof MIME rejection
    with pytest.raises(AppError) as exc_info:
        await topup_service.upload_proof(
            order_id=topup_resp.order_id,
            user_id=user.id,
            filename="malicious.exe",
            content_type="application/x-msdownload",
            file_bytes=b"MZ..."
        )
    assert exc_info.value.code == "invalid_request"

    # 4. Telegram Bot Callback - Unauthorized non-admin attempt
    unauth_callback = {
        "id": "cb_01",
        "from": {"id": 11112222, "username": "intruder"},
        "data": f"topup:approve:{topup_resp.order_id}",
        "message": {"chat": {"id": 123}, "message_id": 456, "text": "Topup msg"}
    }
    unauth_res = await telegram_bot_service.handle_callback_query(
        callback_query=unauth_callback,
        topup_service_fn=topup_service.review_topup
    )
    assert unauth_res["status"] == "forbidden"

    # 5. Telegram Bot Callback - Authorized Admin Approval
    admin_callback = {
        "id": "cb_02",
        "from": {"id": 999888111, "username": "chief_admin"},
        "data": f"topup:approve:{topup_resp.order_id}",
        "message": {"chat": {"id": 999888111}, "message_id": 456, "text": "Topup msg"}
    }
    auth_res = await telegram_bot_service.handle_callback_query(
        callback_query=admin_callback,
        topup_service_fn=topup_service.review_topup
    )
    assert auth_res["status"] == "success"

    # Check that user received the 50,000 credits
    wallet = await wallet_service.get_wallet(user.id)
    assert wallet.balance_credits == 50000

    # 6. Duplicate approval idempotency (no double-credit)
    auth_res_retry = await telegram_bot_service.handle_callback_query(
        callback_query=admin_callback,
        topup_service_fn=topup_service.review_topup
    )
    assert auth_res_retry["status"] == "success"
    wallet_check = await wallet_service.get_wallet(user.id)
    assert wallet_check.balance_credits == 50000  # Still 50,000!
