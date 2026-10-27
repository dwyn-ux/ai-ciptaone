import asyncio
import os
import sys

# Ensure backend root is on sys.path
backend_dir = os.path.dirname(os.path.abspath(__file__))
if backend_dir not in sys.path:
    sys.path.insert(0, backend_dir)

from app.core.database import db
from app.core.security import hash_password, normalize_email
from app.services.auth_service import auth_service
from app.services.wallet_service import wallet_service


async def main():
    email = "admin@ciptaone.com"
    password = "AdminPassword2026!"

    print(f"[*] Inisialisasi skema database jika belum ada...")
    db.init_schema()

    norm_email = normalize_email(email)
    existing = await db.fetch_one("SELECT id, role FROM users WHERE email_normalized = ?", [norm_email])

    if existing:
        await db.execute("UPDATE users SET role = 'admin' WHERE id = ?", [existing["id"]])
        print(f"[OK] Akun {email} sudah ada dan telah diset sebagai role 'admin'.")
        user_id = existing["id"]
    else:
        user = await auth_service.register(email=email, password=password)
        await db.execute("UPDATE users SET role = 'admin' WHERE id = ?", [user.id])
        print(f"[OK] Akun admin berhasil dibuat:")
        print(f"     Email   : {email}")
        print(f"     Password: {password}")
        print(f"     Role    : admin")
        user_id = user.id

    # Give admin initial 100,000 credits for testing playground
    wallet = await wallet_service.get_wallet(user_id)
    if wallet.balance_credits < 50000:
        await wallet_service.credit_wallet_for_topup(user_id, "SEED-ADMIN-BONUS", 100000)
        print(f"[OK] Saldo kredit pengujian sebesar 100.000 kredit telah ditambahkan ke wallet admin.")


if __name__ == "__main__":
    asyncio.run(main())
