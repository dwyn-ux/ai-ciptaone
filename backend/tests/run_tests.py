"""
Standalone Test Runner for Decomu AI Router Test Suite
Executes all unit and integration test modules asynchronously.
"""

import asyncio
import os
import sys
import tempfile
import time

# Ensure backend directory is in sys.path
backend_dir = os.path.abspath(os.path.join(os.path.dirname(__file__), ".."))
if backend_dir not in sys.path:
    sys.path.insert(0, backend_dir)

from app.config import settings
from app.core.database import db

# Import test functions
from tests.test_auth import (
    test_password_hashing,
    test_email_normalization,
    test_user_registration_and_login,
)
from tests.test_api_keys import test_api_key_lifecycle
from tests.test_wallet_ledger import (
    test_wallet_and_ledger_operations,
    test_concurrent_reservation_safety,
)
from tests.test_router_chat import test_calculate_token_cost, test_router_models_and_chat
from tests.test_topup_telegram import test_topup_lifecycle_and_telegram
from tests.test_payment_adapters import (
    test_manual_payment_adapter,
    test_dana_adapter_unconfigured_protection,
)
from tests.test_admin_audit import test_admin_audit_and_balance_adjustment


async def main():
    print("=" * 60)
    print("🚀 RUNNING DECOMU AI ROUTER TEST SUITE")
    print("=" * 60)

    # 1. Initialize isolated test database
    test_db = os.path.join(tempfile.gettempdir(), f"decomu_test_{int(time.time())}.sqlite3")
    settings.DB_MODE = "sqlite"
    settings.SQLITE_PATH = test_db
    db.mode = "sqlite"
    db.sqlite_path = test_db
    db.close()

    migrations_dir = os.path.abspath(os.path.join(backend_dir, "../migrations"))
    db.init_schema(migrations_dir)
    print(f"[*] Initialized test database at: {test_db}\n")

    test_cases = [
        ("Auth: Password Hashing & Verification", test_password_hashing),
        ("Auth: Email Normalization", test_email_normalization),
        ("Auth: Registration, Login & Session Rotation", test_user_registration_and_login),
        ("API Keys: Generation, Hashing, Prefix, Revocation", test_api_key_lifecycle),
        ("Wallet & Ledger: Atomic Reservation, Settlement, Refund, Idempotency", test_wallet_and_ledger_operations),
        ("Wallet & Ledger: Concurrent Race Condition & Abuse Protection", test_concurrent_reservation_safety),
        ("Router: Token Cost Calculation", test_calculate_token_cost),
        ("Router: Models Catalog, Chat Completions, Streaming SSE", test_router_models_and_chat),
        ("Top-up: Invoice Creation, Proof Upload, Telegram Bot Callback & Idempotency", test_topup_lifecycle_and_telegram),
        ("Payment Adapter: Manual Transfer Instructions", test_manual_payment_adapter),
        ("Payment Adapter: DANA Unconfigured Protection", test_dana_adapter_unconfigured_protection),
        ("Admin: Model Controls, Balance Adjustments & Audit Logs", test_admin_audit_and_balance_adjustment),
    ]

    passed = 0
    failed = 0
    start_time = time.time()

    for name, test_func in test_cases:
        sys.stdout.write(f"• Running: {name}... ")
        sys.stdout.flush()
        t0 = time.time()
        try:
            if asyncio.iscoroutinefunction(test_func):
                await test_func()
            else:
                test_func()
            elapsed = time.time() - t0
            print(f"✅ PASSED ({elapsed:.3f}s)")
            passed += 1
        except Exception as e:
            elapsed = time.time() - t0
            print(f"❌ FAILED ({elapsed:.3f}s)")
            print(f"  Error: {e}")
            import traceback
            traceback.print_exc()
            failed += 1

    total_time = time.time() - start_time
    print("\n" + "=" * 60)
    print(f"📊 TEST RESULTS: {passed} PASSED, {failed} FAILED (Total time: {total_time:.2f}s)")
    print("=" * 60)

    # Cleanup test db
    db.close()
    if os.path.exists(test_db):
        try:
            os.remove(test_db)
        except Exception:
            pass

    if failed > 0:
        sys.exit(1)


if __name__ == "__main__":
    asyncio.run(main())
