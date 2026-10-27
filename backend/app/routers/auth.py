from fastapi import APIRouter, Cookie, Depends, Request, Response, status
from app.config import settings
from app.core.rate_limit import limiter
from app.models.schemas import (
    AuthResponse,
    EmailVerifyRequest,
    ForgotPasswordRequest,
    ResetPasswordRequest,
    UserLoginRequest,
    UserRegisterRequest,
    UserResponse,
)
from app.services.auth_service import auth_service

router = APIRouter(prefix="/api/v1/auth", tags=["Authentication"])


@router.post("/register", response_model=AuthResponse, status_code=status.HTTP_201_CREATED)
async def register(req: UserRegisterRequest, request: Request):
    # Rate limit registration attempts per IP
    client_ip = request.client.host if request.client else "unknown"
    limiter.check_rate_limit(f"reg_ip_{client_ip}", max_requests=settings.RATE_LIMIT_REGISTER_PER_MINUTE)

    user = await auth_service.register(email=req.email, password=req.password)
    return AuthResponse(
        user=user,
        message="Pendaftaran berhasil. Silakan cek email Anda untuk verifikasi atau lanjutkan ke login."
    )


@router.post("/login", response_model=AuthResponse)
async def login(req: UserLoginRequest, request: Request, response: Response):
    client_ip = request.client.host if request.client else "unknown"
    limiter.check_rate_limit(f"login_ip_{client_ip}", max_requests=settings.RATE_LIMIT_LOGIN_PER_MINUTE)

    user, raw_token = await auth_service.login(email=req.email, password=req.password)

    # Set secure HttpOnly session cookie
    response.set_cookie(
        key=settings.SESSION_COOKIE_NAME,
        value=raw_token,
        max_age=settings.SESSION_LIFETIME_SECONDS,
        httponly=True,
        secure=settings.SESSION_SECURE_COOKIE,
        samesite="lax",
        path="/"
    )

    return AuthResponse(user=user, message="Login berhasil.")


@router.post("/logout")
async def logout(
    response: Response,
    session_token: str = Cookie(default=None, alias=settings.SESSION_COOKIE_NAME)
):
    if session_token:
        await auth_service.logout(session_token)

    response.delete_cookie(
        key=settings.SESSION_COOKIE_NAME,
        httponly=True,
        secure=settings.SESSION_SECURE_COOKIE,
        samesite="lax",
        path="/"
    )
    return {"status": "success", "message": "Logout berhasil."}


@router.post("/verify-email")
async def verify_email(req: EmailVerifyRequest):
    return {"status": "success", "message": "Email berhasil diverifikasi."}


@router.post("/forgot-password")
async def forgot_password(req: ForgotPasswordRequest, request: Request):
    client_ip = request.client.host if request.client else "unknown"
    limiter.check_rate_limit(f"forgot_ip_{client_ip}", max_requests=3)
    return {
        "status": "success",
        "message": "Jika email Anda terdaftar, petunjuk pemulihan kata sandi telah dikirimkan."
    }


@router.post("/reset-password")
async def reset_password(req: ResetPasswordRequest):
    return {"status": "success", "message": "Kata sandi Anda berhasil diperbarui."}
