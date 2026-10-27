import os
from typing import List
from pydantic import Field
from pydantic_settings import BaseSettings, SettingsConfigDict


class Settings(BaseSettings):
    model_config = SettingsConfigDict(
        env_file=".env",
        env_file_encoding="utf-8",
        extra="ignore"
    )

    APP_ENV: str = Field(default="development")
    DEBUG: bool = Field(default=False)
    SECRET_KEY: str = Field(default="insecure-default-change-in-production-0123456789abcdef")

    # Session & Security
    SESSION_COOKIE_NAME: str = Field(default="decomu_session")
    SESSION_LIFETIME_SECONDS: int = Field(default=7 * 24 * 3600)  # 7 days
    SESSION_SECURE_COOKIE: bool = Field(default=False)  # Set True in production (HTTPS)
    CORS_ORIGINS: str = Field(default="http://localhost:5173,http://localhost:3000,https://decomu.site")

    # Database: 'worker' (Cloudflare D1 via Worker API) or 'sqlite' (local / testing)
    DB_MODE: str = Field(default="sqlite")
    D1_WORKER_URL: str = Field(default="")
    D1_SERVICE_TOKEN: str = Field(default="")
    SQLITE_PATH: str = Field(default="./local_d1.sqlite3")

    # Storage for Private Payment Proofs
    PROOF_UPLOAD_DIR: str = Field(default="./storage/proofs")
    MAX_PROOF_SIZE_BYTES: int = Field(default=5 * 1024 * 1024)  # 5MB

    # Topup Configuration
    TOPUP_EXPIRY_MINUTES: int = Field(default=120)  # 2 hours
    CREDIT_PER_RUPIAH: int = Field(default=1)       # 1 IDR = 1 Credit
    MANUAL_BANK_NAME: str = Field(default="BCA / Mandiri / BRI")
    MANUAL_ACCOUNT_NO: str = Field(default="1234567890")
    MANUAL_ACCOUNT_HOLDER: str = Field(default="Decomu AI Router")
    MANUAL_QRIS_IMAGE_URL: str = Field(default="")

    # Telegram Bot
    TELEGRAM_BOT_TOKEN: str = Field(default="")
    TELEGRAM_ADMIN_IDS: str = Field(default="")  # Comma-separated list of Telegram User IDs
    TELEGRAM_WEBHOOK_SECRET: str = Field(default="")

    # DANA Dynamic QRIS Adapter (Awaiting official merchant & API approval)
    DANA_ENABLED: bool = Field(default=False)
    DANA_ENV: str = Field(default="sandbox")
    DANA_CLIENT_ID: str = Field(default="")
    DANA_CLIENT_SECRET: str = Field(default="")
    DANA_MERCHANT_ID: str = Field(default="")
    DANA_PUBLIC_KEY: str = Field(default="")
    DANA_PRIVATE_KEY: str = Field(default="")

    # Upstream AI Providers
    OPENROUTER_API_KEY: str = Field(default="")
    OPENAI_API_KEY: str = Field(default="")

    # Rate Limiting
    RATE_LIMIT_LOGIN_PER_MINUTE: int = Field(default=5)
    RATE_LIMIT_REGISTER_PER_MINUTE: int = Field(default=3)
    RATE_LIMIT_CHAT_PER_MINUTE: int = Field(default=60)
    RATE_LIMIT_TOPUP_PER_MINUTE: int = Field(default=5)

    @property
    def cors_origin_list(self) -> List[str]:
        return [origin.strip() for origin in self.CORS_ORIGINS.split(",") if origin.strip()]

    @property
    def telegram_admin_id_list(self) -> List[int]:
        ids = []
        for x in self.TELEGRAM_ADMIN_IDS.split(","):
            s = x.strip()
            if s.isdigit():
                ids.append(int(s))
        return ids


settings = Settings()
