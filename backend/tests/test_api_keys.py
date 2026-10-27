import pytest
from app.core.errors import AppError
from app.services.api_key_service import api_key_service
from app.services.auth_service import auth_service


@pytest.mark.asyncio
async def test_api_key_lifecycle():
    # Setup test user
    email = "apikey_tester@decomu.site"
    pwd = "ApiKeyPassword2026!"
    user = await auth_service.register(email=email, password=pwd)

    # 1. Create API Key
    created = await api_key_service.create_api_key(user_id=user.id, name="Test Key 1")
    assert created.id.startswith("key_")
    assert created.key.startswith("sk-live-")
    assert created.key_prefix.startswith("sk-live-")
    assert created.name == "Test Key 1"

    # 2. List API Keys (only prefix returned, never plaintext key)
    keys_list = await api_key_service.list_user_api_keys(user.id)
    assert len(keys_list) >= 1
    found = next((k for k in keys_list if k.id == created.id), None)
    assert found is not None
    assert not hasattr(found, "key")
    assert found.key_prefix == created.key_prefix

    # 3. Validate API Key
    resolved_uid, resolved_key_id = await api_key_service.validate_api_key(created.key)
    assert resolved_uid == user.id
    assert resolved_key_id == created.id

    # 4. Invalid API Key
    with pytest.raises(AppError) as exc_info:
        await api_key_service.validate_api_key("sk-live-invalid-key-token")
    assert exc_info.value.code == "unauthorized"

    # 5. Revoke API Key
    await api_key_service.revoke_api_key(user.id, created.id)
    with pytest.raises(AppError) as exc_info:
        await api_key_service.validate_api_key(created.key)
    assert exc_info.value.code == "unauthorized"
