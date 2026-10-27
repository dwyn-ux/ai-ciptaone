import pytest
from app.core.errors import AppError
from app.services.auth_service import auth_service
from app.services.wallet_service import wallet_service


@pytest.mark.asyncio
async def test_wallet_and_ledger_operations():
    # 1. Setup user with 0 initial balance
    user = await auth_service.register(email="wallet_test@decomu.site", password="Password123!")
    wallet = await wallet_service.get_wallet(user.id)
    assert wallet.balance_credits == 0

    # 2. Cannot reserve credits with 0 balance
    with pytest.raises(AppError) as exc_info:
        await wallet_service.reserve_credits(user.id, "req_test_01", max_credits=500)
    assert exc_info.value.code == "insufficient_credits"

    # 3. Credit wallet from Top-up (e.g. 50,000 IDR -> 50,000 credits)
    order_id = "INV-TEST-001"
    new_bal = await wallet_service.credit_wallet_for_topup(
        user_id=user.id,
        order_id=order_id,
        credits_to_grant=50000,
        metadata={"source": "unit_test"}
    )
    assert new_bal == 50000

    wallet = await wallet_service.get_wallet(user.id)
    assert wallet.balance_credits == 50000

    # 4. Idempotency test: calling topup credit twice with same order_id does not double-credit
    new_bal_retry = await wallet_service.credit_wallet_for_topup(
        user_id=user.id,
        order_id=order_id,
        credits_to_grant=50000,
    )
    assert new_bal_retry == 50000
    wallet = await wallet_service.get_wallet(user.id)
    assert wallet.balance_credits == 50000

    # 5. Reserve credits for AI completion
    req_id = "req_ai_test_01"
    res_id = await wallet_service.reserve_credits(user.id, req_id, max_credits=1000)
    assert res_id.startswith("res_")

    wallet = await wallet_service.get_wallet(user.id)
    assert wallet.balance_credits == 49000  # 50,000 - 1,000 reserved

    # 6. Settle reservation with actual cost (e.g. only 400 credits spent)
    # The 600 unspent credits must be refunded back to user's wallet
    actual_billed = await wallet_service.settle_credits(
        user_id=user.id,
        request_id=req_id,
        actual_billed_credits=400,
        metadata={"tokens": 200}
    )
    assert actual_billed == 400

    wallet = await wallet_service.get_wallet(user.id)
    assert wallet.balance_credits == 49600  # 49,000 + 600 refund = 49,600!

    # 7. Release reservation on failure test
    req_fail = "req_ai_fail_02"
    await wallet_service.reserve_credits(user.id, req_fail, max_credits=600)
    wallet = await wallet_service.get_wallet(user.id)
    assert wallet.balance_credits == 49000

    await wallet_service.release_reservation(user.id, req_fail)
    wallet = await wallet_service.get_wallet(user.id)
    assert wallet.balance_credits == 49600  # Full refund back

    # 8. Check ledger transaction history
    tx_list = await wallet_service.list_transactions(user.id)
    assert tx_list.total >= 2
    # Verify ledger entries exist for topup and chat_usage
    event_types = [item.event_type for item in tx_list.items]
    assert "topup" in event_types
    assert "chat_usage" in event_types


@pytest.mark.asyncio
async def test_concurrent_reservation_safety():
    """
    Abuse & Concurrency test:
    Two concurrent requests competing for the same balance must never
    cause negative balance or double reservation.
    """
    import asyncio

    user = await auth_service.register(email="race_condition_test@decomu.site", password="RacePassword123!")
    await wallet_service.credit_wallet_for_topup(user.id, "INV-RACE-01", 1000)

    # Both try to reserve 800 credits concurrently
    results = await asyncio.gather(
        wallet_service.reserve_credits(user.id, "req_race_1", 800),
        wallet_service.reserve_credits(user.id, "req_race_2", 800),
        return_exceptions=True
    )

    successes = [r for r in results if isinstance(r, str) and r.startswith("res_")]
    failures = [r for r in results if isinstance(r, AppError) and r.code == "insufficient_credits"]

    assert len(successes) == 1, "Exactly one concurrent reservation must succeed"
    assert len(failures) == 1, "The competing concurrent reservation must be rejected with insufficient_credits"

    wallet = await wallet_service.get_wallet(user.id)
    assert wallet.balance_credits == 200, "Remaining balance must be exactly 200 (1000 - 800)"

