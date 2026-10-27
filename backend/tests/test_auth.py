import pytest
from app.core.errors import AppError
from app.core.security import hash_password, verify_password, normalize_email
from app.services.auth_service import auth_service


@pytest.mark.asyncio
async def test_password_hashing():
    pwd = "SuperSecretPassword123!"
    hashed = hash_password(pwd)
    assert hashed != pwd
    assert verify_password(pwd, hashed) is True
    assert verify_password("WrongPassword!", hashed) is False


@pytest.mark.asyncio
async def test_email_normalization():
    assert normalize_email("  User.Test@Example.COM  ") == "user.test@example.com"


@pytest.mark.asyncio
async def test_user_registration_and_login():
    email = "testuser@ciptaone.com"
    password = "SecurePassword2026!"

    # 1. Register
    user_resp = await auth_service.register(email=email, password=password)
    assert user_resp.id.startswith("usr_")
    assert user_resp.email == email
    assert user_resp.role == "user"

    # 2. Prevent duplicate registration without leaking
    with pytest.raises(AppError) as exc_info:
        await auth_service.register(email=email, password=password)
    assert exc_info.value.code == "invalid_request"

    # 3. Successful Login
    user_logged, raw_token = await auth_service.login(email=email, password=password)
    assert user_logged.id == user_resp.id
    assert len(raw_token) > 20

    # 4. Resolve User from Session Token
    user_resolved = await auth_service.get_user_from_session(raw_token)
    assert user_resolved is not None
    assert user_resolved.id == user_resp.id

    # 5. Invalid Password Login
    with pytest.raises(AppError) as exc_info:
        await auth_service.login(email=email, password="IncorrectPassword")
    assert exc_info.value.code == "unauthorized"

    # 6. Logout
    await auth_service.logout(raw_token)
    assert await auth_service.get_user_from_session(raw_token) is None
