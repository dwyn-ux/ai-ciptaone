from typing import Optional
from fastapi import Cookie, Depends, Header, Request
from app.config import settings
from app.core.errors import AppError, ErrorCode
from app.models.schemas import UserResponse
from app.services.api_key_service import api_key_service
from app.services.auth_service import auth_service


async def get_current_user_from_session(
    request: Request,
    session_token: Optional[str] = Cookie(default=None, alias=settings.SESSION_COOKIE_NAME),
    authorization: Optional[str] = Header(default=None),
) -> UserResponse:
    """
    Extracts current authenticated user from either:
    1. HttpOnly Session Cookie
    2. Bearer <session_token> header (useful for API clients/SPA)
    """
    token = session_token
    if not token and authorization and authorization.startswith("Bearer "):
        bearer_val = authorization[7:].strip()
        # If it doesn't look like an API key (sk-live-), treat as session token
        if not bearer_val.startswith("sk-live-"):
            token = bearer_val

    if not token:
        raise AppError(code=ErrorCode.UNAUTHORIZED, message="Silakan login terlebih dahulu.", status_code=401)

    user = await auth_service.get_user_from_session(token)
    if not user:
        raise AppError(code=ErrorCode.UNAUTHORIZED, message="Sesi telah kedaluwarsa. Silakan login kembali.", status_code=401)

    return user


async def get_current_admin_user(
    current_user: UserResponse = Depends(get_current_user_from_session),
) -> UserResponse:
    """Verifies that the authenticated user possesses admin privileges."""
    if current_user.role != "admin":
        raise AppError(code=ErrorCode.FORBIDDEN, message="Akses khusus admin.", status_code=403)
    return current_user


async def get_auth_for_completion(
    request: Request,
    authorization: Optional[str] = Header(default=None),
    session_token: Optional[str] = Cookie(default=None, alias=settings.SESSION_COOKIE_NAME),
) -> tuple[str, Optional[str]]:
    """
    Resolves user identity for /v1/chat/completions.
    Supports either:
    1. API Key: 'Authorization: Bearer sk-live-...' -> returns (user_id, api_key_id)
    2. Web Session Cookie (Playground) -> returns (user_id, None)
    """
    if authorization and authorization.startswith("Bearer "):
        raw_key = authorization[7:].strip()
        if raw_key.startswith("sk-live-"):
            user_id, api_key_id = await api_key_service.validate_api_key(raw_key)
            return user_id, api_key_id

    # Fallback to session cookie (e.g. for playground chat UI)
    token = session_token
    if not token and authorization and authorization.startswith("Bearer "):
        token = authorization[7:].strip()

    if token:
        user = await auth_service.get_user_from_session(token)
        if user:
            return user.id, None

    raise AppError(
        code=ErrorCode.UNAUTHORIZED,
        message="Autentikasi diperlukan. Sertakan 'Authorization: Bearer <api_key>' atau login ke dashboard.",
        status_code=401,
    )
