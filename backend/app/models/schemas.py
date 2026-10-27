from datetime import datetime
from typing import Any, Dict, List, Literal, Optional, Union
from pydantic import BaseModel, EmailStr, Field


# ------------------ Auth Schemas ------------------

class UserRegisterRequest(BaseModel):
    email: EmailStr
    password: str = Field(min_length=8, max_length=128)


class UserLoginRequest(BaseModel):
    email: EmailStr
    password: str = Field(min_length=1, max_length=128)


class EmailVerifyRequest(BaseModel):
    token: str


class ForgotPasswordRequest(BaseModel):
    email: EmailStr


class ResetPasswordRequest(BaseModel):
    token: str
    new_password: str = Field(min_length=8, max_length=128)


class UserResponse(BaseModel):
    id: str
    email: str
    email_verified: bool
    status: str
    role: str
    created_at: str


class AuthResponse(BaseModel):
    user: UserResponse
    message: Optional[str] = None


# ------------------ API Key Schemas ------------------

class ApiKeyCreateRequest(BaseModel):
    name: str = Field(min_length=1, max_length=64)


class ApiKeyResponse(BaseModel):
    id: str
    name: str
    key_prefix: str
    last_used_at: Optional[str] = None
    created_at: str


class ApiKeyCreatedResponse(ApiKeyResponse):
    key: str  # Plaintext key returned ONCE only at creation


# ------------------ Wallet & Transactions ------------------

class WalletResponse(BaseModel):
    balance_credits: int
    currency: str = "IDR"
    updated_at: str


class TransactionItem(BaseModel):
    id: str
    event_type: str
    amount_credits: int
    balance_after: int
    reference_type: str
    reference_id: str
    metadata: Optional[Dict[str, Any]] = None
    created_at: str


class TransactionListResponse(BaseModel):
    items: List[TransactionItem]
    total: int


class UsageItem(BaseModel):
    id: str
    request_id: str
    model_id: str
    provider_id: str
    input_tokens: int
    output_tokens: int
    billed_credits: int
    latency_ms: int
    status: str
    streaming: bool
    created_at: str


class UsageListResponse(BaseModel):
    items: List[UsageItem]
    total: int


# ------------------ Models & Providers ------------------

class ModelItem(BaseModel):
    id: str
    public_id: str
    provider_id: str
    provider_slug: str
    display_name: str
    upstream_model: str
    enabled: bool
    input_price_per_million: int
    output_price_per_million: int
    currency: str
    max_input_tokens: int
    max_output_tokens: int
    supports_streaming: bool
    pricing_version: int


class ProviderItem(BaseModel):
    id: str
    slug: str
    display_name: str
    enabled: bool
    commercial_use_approved: bool
    terms_checked_at: Optional[str] = None


# ------------------ OpenAI Compatible Schemas ------------------

class OpenAIModelItem(BaseModel):
    id: str
    object: str = "model"
    created: int = 1714000000
    owned_by: str = "decomu"


class OpenAIModelListResponse(BaseModel):
    object: str = "list"
    data: List[OpenAIModelItem]


class ChatCompletionMessage(BaseModel):
    role: Literal["system", "user", "assistant"]
    content: str


class ChatCompletionRequest(BaseModel):
    model: str
    messages: List[ChatCompletionMessage]
    stream: bool = False
    max_tokens: Optional[int] = Field(default=None, ge=1, le=16384)
    temperature: Optional[float] = Field(default=0.7, ge=0.0, le=2.0)


class ChatCompletionChoiceMessage(BaseModel):
    role: str = "assistant"
    content: str


class ChatCompletionChoice(BaseModel):
    index: int = 0
    message: ChatCompletionChoiceMessage
    finish_reason: str = "stop"


class ChatCompletionUsage(BaseModel):
    prompt_tokens: int
    completion_tokens: int
    total_tokens: int


class ChatCompletionResponse(BaseModel):
    id: str
    object: str = "chat.completion"
    created: int
    model: str
    choices: List[ChatCompletionChoice]
    usage: ChatCompletionUsage


class ChatCompletionChunkDelta(BaseModel):
    role: Optional[str] = None
    content: Optional[str] = None


class ChatCompletionChunkChoice(BaseModel):
    index: int = 0
    delta: ChatCompletionChunkDelta
    finish_reason: Optional[str] = None


class ChatCompletionChunk(BaseModel):
    id: str
    object: str = "chat.completion.chunk"
    created: int
    model: str
    choices: List[ChatCompletionChunkChoice]


# ------------------ Topup Schemas ------------------

class TopupCreateRequest(BaseModel):
    amount_minor: int = Field(ge=10000, le=5000000, description="Nominal dalam Rupiah (min Rp10.000, max Rp5.000.000)")
    payment_method: Literal["manual_transfer", "manual_qris", "dana_qris_dynamic"] = "manual_transfer"


class TopupResponse(BaseModel):
    topup_id: str
    order_id: str
    amount_minor: int
    currency: str
    credits_to_grant: int
    payment_method: str
    status: str
    expires_at: str
    checkout_url: Optional[str] = None
    instructions: Dict[str, Any]


class AdminTopupReviewRequest(BaseModel):
    action: Literal["approve", "reject"]
    rejection_reason: Optional[str] = None


class AdminBalanceAdjustRequest(BaseModel):
    user_id: str
    amount_credits: int  # Signed integer: positive to add, negative to subtract
    reason: str = Field(min_length=5, max_length=255)


class AdminModelUpdateRequest(BaseModel):
    enabled: Optional[bool] = None
    input_price_per_million: Optional[int] = Field(default=None, ge=0)
    output_price_per_million: Optional[int] = Field(default=None, ge=0)
    max_input_tokens: Optional[int] = Field(default=None, ge=1)
    max_output_tokens: Optional[int] = Field(default=None, ge=1)
