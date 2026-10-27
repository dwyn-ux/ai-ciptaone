import pytest
from app.core.database import db
from app.services.audit_service import audit_service
from app.services.auth_service import auth_service
from app.services.wallet_service import wallet_service


@pytest.mark.asyncio
async def test_admin_audit_and_balance_adjustment():
    # 1. Setup admin and regular user
    admin_user = await auth_service.register(email="admin_auditor@ciptaone.com", password="AdminPassword123!")
    await db.execute("UPDATE users SET role = 'admin' WHERE id = ?", [admin_user.id])

    target_user = await auth_service.register(email="victim_user@ciptaone.com", password="UserPassword123!")

    # 2. Admin adds 25,000 credits to user
    new_bal = await wallet_service.adjust_balance_admin(
        admin_user_id=admin_user.id,
        target_user_id=target_user.id,
        amount_credits=25000,
        reason="Kompensasi gangguan teknis",
    )
    assert new_bal == 25000

    wallet = await wallet_service.get_wallet(target_user.id)
    assert wallet.balance_credits == 25000

    # 3. Admin deducts 5,000 credits
    new_bal_sub = await wallet_service.adjust_balance_admin(
        admin_user_id=admin_user.id,
        target_user_id=target_user.id,
        amount_credits=-5000,
        reason="Koreksi kelebihan kredit",
    )
    assert new_bal_sub == 20000

    # 4. Check audit logs
    audit_logs = await audit_service.list_audit_logs()
    assert len(audit_logs) >= 2
    actions = [l["action"] for l in audit_logs]
    assert "balance_adjustment" in actions
