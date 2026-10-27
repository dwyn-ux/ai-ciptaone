import pytest
from app.core.errors import AppError
from app.services.payment_adapters.dana import DanaPaymentAdapter
from app.services.payment_adapters.manual import ManualPaymentAdapter


@pytest.mark.asyncio
async def test_manual_payment_adapter():
    adapter = ManualPaymentAdapter("manual_transfer")
    res = await adapter.create_invoice(
        order_id="INV-MANUAL-01",
        amount_minor=100000,
        currency="IDR",
        user_id="usr_test",
        user_email="test@decomu.site",
    )
    assert res["checkout_url"] is None
    assert "instructions" in res
    assert res["instructions"]["method"] == "manual_transfer"
    assert res["instructions"]["amount"] == 100000


@pytest.mark.asyncio
async def test_dana_adapter_unconfigured_protection():
    adapter = DanaPaymentAdapter()
    # When DANA_ENABLED is False or credentials missing, attempts must be cleanly rejected
    with pytest.raises(AppError) as exc_info:
        await adapter.create_invoice(
            order_id="INV-DANA-01",
            amount_minor=50000,
            currency="IDR",
            user_id="usr_test",
            user_email="test@decomu.site",
        )
    assert exc_info.value.code == "invalid_request"
    assert "DANA QRIS Dinamis belum aktif" in exc_info.value.message
