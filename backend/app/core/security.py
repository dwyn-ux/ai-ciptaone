import hashlib
import hmac
import secrets
from typing import Tuple
from passlib.context import CryptContext

# Configure password hashing context with Argon2 as primary and bcrypt as fallback
pwd_context = CryptContext(
    schemes=["argon2", "bcrypt"],
    deprecated="auto"
)


def hash_password(password: str) -> str:
    """Hashes a plaintext password using Argon2id / bcrypt."""
    return pwd_context.hash(password)


def verify_password(plain_password: str, hashed_password: str) -> bool:
    """Verifies a plaintext password against a stored hash."""
    return pwd_context.verify(plain_password, hashed_password)


def normalize_email(email: str) -> str:
    """Normalizes an email address to lowercase and trimmed."""
    return email.strip().lower()


def generate_session_token() -> Tuple[str, str]:
    """
    Generates a cryptographically secure session token and its SHA-256 hash.
    Returns: (raw_token, token_hash)
    """
    raw_token = secrets.token_urlsafe(48)
    token_hash = hash_token(raw_token)
    return raw_token, token_hash


def hash_token(token: str) -> str:
    """Computes SHA-256 hex digest of a token."""
    return hashlib.sha256(token.encode("utf-8")).hexdigest()


def generate_api_key() -> Tuple[str, str, str]:
    """
    Generates an OpenAI-compatible API key.
    Format: sk-live-<32 random chars>
    Returns: (raw_key, key_prefix, key_hash)
    """
    random_part = secrets.token_urlsafe(32)
    raw_key = f"sk-live-{random_part}"
    key_prefix = f"sk-live-{random_part[:6]}..."
    key_hash = hash_token(raw_key)
    return raw_key, key_prefix, key_hash


def secure_compare(a: str, b: str) -> bool:
    """Constant-time comparison to prevent timing attacks."""
    return hmac.compare_digest(a.encode("utf-8"), b.encode("utf-8"))
