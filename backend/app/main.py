from contextlib import asynccontextmanager
import logging
import uuid
from fastapi import FastAPI, Request, status
from fastapi.exceptions import RequestValidationError
from fastapi.middleware.cors import CORSMiddleware
from fastapi.responses import JSONResponse
from starlette.middleware.base import BaseHTTPMiddleware
from app.config import settings
from app.core.database import db
from app.core.errors import AppError, ErrorCode, format_error_response
from app.routers import admin, auth, health, me, api_keys, openai_v1, topups, webhooks

# Configure logging
logging.basicConfig(
    level=logging.INFO if not settings.DEBUG else logging.DEBUG,
    format="%(asctime)s [%(levelname)s] %(name)s: %(message)s",
)
logger = logging.getLogger("decomu_router")


@asynccontextmanager
async def lifespan(app: FastAPI):
    # Startup: Initialize SQLite tables/migrations if in SQLite mode
    logger.info(f"Starting decomu-ai-router (Env: {settings.APP_ENV}, DB_MODE: {settings.DB_MODE})")
    if settings.DB_MODE == "sqlite":
        db.init_schema()
        logger.info("Local SQLite database schema initialized.")
    yield
    # Shutdown
    db.close()
    logger.info("Application shutdown complete.")


app = FastAPI(
    title="Decomu AI Router Gateway",
    description="High-performance, secure AI Router & API Gateway compatible with OpenAI standards",
    version="0.1.0",
    lifespan=lifespan,
)

# ------------------ Security Headers Middleware ------------------
class SecurityHeadersMiddleware(BaseHTTPMiddleware):
    async def dispatch(self, request: Request, call_next):
        response = await call_next(request)
        response.headers["X-Content-Type-Options"] = "nosniff"
        response.headers["X-Frame-Options"] = "DENY"
        response.headers["X-XSS-Protection"] = "1; mode=block"
        response.headers["Referrer-Policy"] = "strict-origin-when-cross-origin"
        return response

app.add_middleware(SecurityHeadersMiddleware)

# ------------------ CORS Middleware ------------------
app.add_middleware(
    CORSMiddleware,
    allow_origins=settings.cors_origin_list,
    allow_credentials=True,
    allow_methods=["GET", "POST", "PUT", "PATCH", "DELETE", "OPTIONS"],
    allow_headers=["*"],
    expose_headers=["Content-Type", "Authorization", "X-Accel-Buffering"],
)

# ------------------ Exception Handlers ------------------
@app.exception_handler(AppError)
async def app_error_handler(request: Request, exc: AppError):
    return JSONResponse(status_code=exc.status_code, content=exc.to_envelope())


@app.exception_handler(RequestValidationError)
async def validation_error_handler(request: Request, exc: RequestValidationError):
    req_id = f"req_{uuid.uuid4().hex[:12]}"
    errors = exc.errors()
    msg = errors[0]["msg"] if errors else "Validasi input gagal."
    return format_error_response(
        code=ErrorCode.INVALID_REQUEST,
        message=f"Input tidak valid: {msg}",
        status_code=status.HTTP_422_UNPROCESSABLE_ENTITY,
        request_id=req_id,
        details={"validation_errors": errors},
    )


@app.exception_handler(Exception)
async def unhandled_exception_handler(request: Request, exc: Exception):
    req_id = f"req_{uuid.uuid4().hex[:12]}"
    logger.exception(f"Unhandled server error [{req_id}]: {exc}")
    return format_error_response(
        code=ErrorCode.INTERNAL_ERROR,
        message="Terjadi kesalahan internal pada server. Silakan coba beberapa saat lagi.",
        status_code=status.HTTP_500_INTERNAL_SERVER_ERROR,
        request_id=req_id,
    )


# ------------------ Router Mounts ------------------
app.include_router(health.router)
app.include_router(auth.router)
app.include_router(me.router)
app.include_router(api_keys.router)
app.include_router(openai_v1.router)
app.include_router(topups.router)
app.include_router(webhooks.router)
app.include_router(admin.router)
