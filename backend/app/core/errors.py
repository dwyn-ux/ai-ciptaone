import uuid
from typing import Optional, Dict, Any
from fastapi import HTTPException, Request, status
from fastapi.responses import JSONResponse


class ErrorCode:
    INVALID_REQUEST = "invalid_request"
    UNAUTHORIZED = "unauthorized"
    FORBIDDEN = "forbidden"
    RATE_LIMIT_EXCEEDED = "rate_limit_exceeded"
    INSUFFICIENT_CREDITS = "insufficient_credits"
    MODEL_UNAVAILABLE = "model_unavailable"
    PROVIDER_TIMEOUT = "provider_timeout"
    PROVIDER_ERROR = "provider_error"
    PAYMENT_PENDING = "payment_pending"
    PAYMENT_VERIFICATION_FAILED = "payment_verification_failed"
    INTERNAL_ERROR = "internal_error"
    NOT_FOUND = "not_found"


class AppError(Exception):
    def __init__(
        self,
        code: str,
        message: str,
        status_code: int = status.HTTP_400_BAD_REQUEST,
        request_id: Optional[str] = None,
        details: Optional[Dict[str, Any]] = None,
    ):
        super().__init__(message)
        self.code = code
        self.message = message
        self.status_code = status_code
        self.request_id = request_id or f"req_{uuid.uuid4().hex[:12]}"
        self.details = details or {}

    def to_envelope(self) -> Dict[str, Any]:
        envelope: Dict[str, Any] = {
            "error": {
                "code": self.code,
                "message": self.message,
                "request_id": self.request_id,
            }
        }
        if self.details:
            envelope["error"]["details"] = self.details
        return envelope


def format_error_response(
    code: str,
    message: str,
    status_code: int,
    request_id: Optional[str] = None,
    details: Optional[Dict[str, Any]] = None,
) -> JSONResponse:
    req_id = request_id or f"req_{uuid.uuid4().hex[:12]}"
    payload: Dict[str, Any] = {
        "error": {
            "code": code,
            "message": message,
            "request_id": req_id,
        }
    }
    if details:
        payload["error"]["details"] = details
    return JSONResponse(status_code=status_code, content=payload)
