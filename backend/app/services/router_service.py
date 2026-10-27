import json
import math
import time
import uuid
from datetime import datetime, timezone
from typing import AsyncGenerator, Dict, Any, List, Optional, Tuple
from app.config import settings
from app.core.database import db
from app.core.errors import AppError, ErrorCode
from app.models.schemas import (
    ChatCompletionChoice,
    ChatCompletionChoiceMessage,
    ChatCompletionChunk,
    ChatCompletionChunkChoice,
    ChatCompletionChunkDelta,
    ChatCompletionRequest,
    ChatCompletionResponse,
    ChatCompletionUsage,
    ModelItem,
    OpenAIModelItem,
    OpenAIModelListResponse,
    UsageItem,
    UsageListResponse,
)
from app.services.provider_adapters import (
    BaseProviderAdapter,
    MockProviderAdapter,
    OpenAICompatibleAdapter,
)
from app.services.wallet_service import wallet_service


def calculate_token_cost(
    input_tokens: int,
    output_tokens: int,
    input_price_per_million: int,
    output_price_per_million: int,
) -> int:
    """
    Computes integer credit cost for prompt and completion tokens.
    Uses ceiling integer arithmetic to protect against fractional loss.
    """
    cost_in = math.ceil((input_tokens * input_price_per_million) / 1_000_000)
    cost_out = math.ceil((output_tokens * output_price_per_million) / 1_000_000)
    total_minor = cost_in + cost_out
    total_credits = total_minor * settings.CREDIT_PER_RUPIAH
    # Minimum 1 credit charge if any tokens were processed
    if (input_tokens > 0 or output_tokens > 0) and total_credits == 0:
        total_credits = 1
    return total_credits


class RouterService:
    def __init__(self):
        self.mock_adapter = MockProviderAdapter()

    def _get_adapter(self, provider_slug: str) -> BaseProviderAdapter:
        if provider_slug == "mock":
            return self.mock_adapter
        elif provider_slug == "openrouter":
            return OpenAICompatibleAdapter(
                base_url="https://openrouter.ai/api/v1",
                api_key=settings.OPENROUTER_API_KEY
            )
        elif provider_slug == "openai":
            return OpenAICompatibleAdapter(
                base_url="https://api.openai.com/v1",
                api_key=settings.OPENAI_API_KEY
            )
        else:
            raise AppError(
                code=ErrorCode.MODEL_UNAVAILABLE,
                message=f"Provider '{provider_slug}' belum memiliki adapter aktif.",
                status_code=503
            )

    async def list_active_models(self) -> List[ModelItem]:
        rows = await db.fetch_all(
            """
            SELECT m.id, m.public_id, m.provider_id, p.slug as provider_slug, p.display_name,
                   m.upstream_model, m.enabled, m.input_price_per_million, m.output_price_per_million,
                   m.currency, m.max_input_tokens, m.max_output_tokens, m.supports_streaming, m.pricing_version
            FROM models m
            JOIN providers p ON m.provider_id = p.id
            WHERE m.enabled = 1 AND p.enabled = 1
            ORDER BY m.public_id ASC
            """
        )
        return [
            ModelItem(
                id=r["id"],
                public_id=r["public_id"],
                provider_id=r["provider_id"],
                provider_slug=r["provider_slug"],
                display_name=r["display_name"],
                upstream_model=r["upstream_model"],
                enabled=bool(r["enabled"]),
                input_price_per_million=r["input_price_per_million"],
                output_price_per_million=r["output_price_per_million"],
                currency=r["currency"],
                max_input_tokens=r["max_input_tokens"],
                max_output_tokens=r["max_output_tokens"],
                supports_streaming=bool(r["supports_streaming"]),
                pricing_version=r["pricing_version"],
            )
            for r in rows
        ]

    async def list_openai_models(self) -> OpenAIModelListResponse:
        active_models = await self.list_active_models()
        return OpenAIModelListResponse(
            object="list",
            data=[OpenAIModelItem(id=m.public_id) for m in active_models]
        )

    async def get_model_by_public_id(self, public_id: str) -> Optional[Dict[str, Any]]:
        row = await db.fetch_one(
            """
            SELECT m.id, m.public_id, m.provider_id, p.slug as provider_slug, p.enabled as provider_enabled,
                   m.upstream_model, m.enabled as model_enabled, m.input_price_per_million,
                   m.output_price_per_million, m.currency, m.max_input_tokens, m.max_output_tokens,
                   m.supports_streaming, m.pricing_version
            FROM models m
            JOIN providers p ON m.provider_id = p.id
            WHERE m.public_id = ?
            """,
            [public_id]
        )
        return row

    async def chat_completion(
        self,
        user_id: str,
        request: ChatCompletionRequest,
        api_key_id: Optional[str] = None
    ) -> ChatCompletionResponse:
        start_time = time.time()
        request_id = f"req_{uuid.uuid4().hex[:16]}"
        now_iso = datetime.now(timezone.utc).isoformat()

        # Step 1: Validate Model
        model_row = await self.get_model_by_public_id(request.model)
        if not model_row or not model_row["model_enabled"] or not model_row["provider_enabled"]:
            raise AppError(
                code=ErrorCode.MODEL_UNAVAILABLE,
                message=f"Model '{request.model}' tidak tersedia atau sedang dinonaktifkan.",
                status_code=400,
                request_id=request_id
            )

        # Step 2: Estimate token usage and max credits to reserve
        total_prompt_chars = sum(len(m.content) for m in request.messages)
        est_input_tokens = max(10, total_prompt_chars // 4)
        max_output_tokens = min(request.max_tokens or model_row["max_output_tokens"], model_row["max_output_tokens"])

        max_reserve_credits = calculate_token_cost(
            input_tokens=est_input_tokens,
            output_tokens=max_output_tokens,
            input_price_per_million=model_row["input_price_per_million"],
            output_price_per_million=model_row["output_price_per_million"]
        )

        # Step 3: Reserve Credits Atomically
        await wallet_service.reserve_credits(user_id, request_id, max_reserve_credits)

        # Step 4: Execute Upstream Call
        adapter = self._get_adapter(model_row["provider_slug"])
        formatted_messages = [{"role": m.role, "content": m.content} for m in request.messages]

        try:
            result = await adapter.chat_completion(
                model_name=model_row["upstream_model"],
                messages=formatted_messages,
                max_tokens=max_output_tokens,
                temperature=request.temperature or 0.7
            )
        except Exception as e:
            # Release reservation completely on error
            await wallet_service.release_reservation(user_id, request_id)
            if isinstance(e, AppError):
                e.request_id = request_id
                raise e
            raise AppError(
                code=ErrorCode.PROVIDER_ERROR,
                message=f"Terjadi kesalahan saat memproses permintaan ke provider: {str(e)}",
                status_code=502,
                request_id=request_id
            )

        latency_ms = int((time.time() - start_time) * 1000)

        # Step 5: Settle actual credit usage
        actual_input_tokens = result.get("prompt_tokens", est_input_tokens)
        actual_output_tokens = result.get("completion_tokens", 10)
        actual_billed_credits = calculate_token_cost(
            input_tokens=actual_input_tokens,
            output_tokens=actual_output_tokens,
            input_price_per_million=model_row["input_price_per_million"],
            output_price_per_million=model_row["output_price_per_million"]
        )

        await wallet_service.settle_credits(
            user_id=user_id,
            request_id=request_id,
            actual_billed_credits=actual_billed_credits,
            metadata={
                "model": request.model,
                "input_tokens": actual_input_tokens,
                "output_tokens": actual_output_tokens,
                "latency_ms": latency_ms
            }
        )

        # Step 6: Record usage event (no prompts stored per PRD)
        usage_event_id = f"usg_{uuid.uuid4().hex[:16]}"
        await db.execute(
            """
            INSERT INTO usage_events (id, request_id, user_id, api_key_id, model_id, provider_id,
                                      input_tokens, output_tokens, estimated_cost_minor, billed_credits,
                                      latency_ms, status, error_code, streaming, created_at)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'success', NULL, 0, ?)
            """,
            [
                usage_event_id, request_id, user_id, api_key_id, model_row["id"], model_row["provider_id"],
                actual_input_tokens, actual_output_tokens, actual_billed_credits, actual_billed_credits,
                latency_ms, now_iso
            ]
        )

        return ChatCompletionResponse(
            id=request_id,
            object="chat.completion",
            created=int(time.time()),
            model=request.model,
            choices=[
                ChatCompletionChoice(
                    index=0,
                    message=ChatCompletionChoiceMessage(
                        role="assistant",
                        content=result["content"]
                    ),
                    finish_reason=result.get("finish_reason", "stop")
                )
            ],
            usage=ChatCompletionUsage(
                prompt_tokens=actual_input_tokens,
                completion_tokens=actual_output_tokens,
                total_tokens=actual_input_tokens + actual_output_tokens
            )
        )

    async def chat_completion_stream(
        self,
        user_id: str,
        request: ChatCompletionRequest,
        api_key_id: Optional[str] = None
    ) -> AsyncGenerator[str, None]:
        """
        Streaming chat completion complying with OpenAI Server-Sent Events (SSE) format.
        Outputs 'data: {...}\n\n' followed by 'data: [DONE]\n\n'.
        """
        start_time = time.time()
        request_id = f"req_{uuid.uuid4().hex[:16]}"
        created_timestamp = int(start_time)
        now_iso = datetime.now(timezone.utc).isoformat()

        model_row = await self.get_model_by_public_id(request.model)
        if not model_row or not model_row["model_enabled"] or not model_row["provider_enabled"]:
            err_envelope = {
                "error": {
                    "code": ErrorCode.MODEL_UNAVAILABLE,
                    "message": f"Model '{request.model}' tidak tersedia.",
                    "request_id": request_id
                }
            }
            yield f"data: {json.dumps(err_envelope)}\n\n"
            yield "data: [DONE]\n\n"
            return

        total_prompt_chars = sum(len(m.content) for m in request.messages)
        est_input_tokens = max(10, total_prompt_chars // 4)
        max_output_tokens = min(request.max_tokens or model_row["max_output_tokens"], model_row["max_output_tokens"])

        max_reserve_credits = calculate_token_cost(
            input_tokens=est_input_tokens,
            output_tokens=max_output_tokens,
            input_price_per_million=model_row["input_price_per_million"],
            output_price_per_million=model_row["output_price_per_million"]
        )

        try:
            await wallet_service.reserve_credits(user_id, request_id, max_reserve_credits)
        except AppError as ae:
            yield f"data: {json.dumps(ae.to_envelope())}\n\n"
            yield "data: [DONE]\n\n"
            return

        adapter = self._get_adapter(model_row["provider_slug"])
        formatted_messages = [{"role": m.role, "content": m.content} for m in request.messages]

        output_token_count = 0
        prompt_token_count = est_input_tokens

        try:
            async for chunk in adapter.chat_completion_stream(
                model_name=model_row["upstream_model"],
                messages=formatted_messages,
                max_tokens=max_output_tokens,
                temperature=request.temperature or 0.7
            ):
                delta = chunk.get("delta", {})
                finish_reason = chunk.get("finish_reason")
                if delta.get("content"):
                    output_token_count += len(delta["content"].split())

                if "usage" in chunk and chunk["usage"]:
                    prompt_token_count = chunk["usage"].get("prompt_tokens", prompt_token_count)
                    output_token_count = chunk["usage"].get("completion_tokens", output_token_count)

                chunk_obj = ChatCompletionChunk(
                    id=request_id,
                    created=created_timestamp,
                    model=request.model,
                    choices=[
                        ChatCompletionChunkChoice(
                            index=0,
                            delta=ChatCompletionChunkDelta(
                                role=delta.get("role"),
                                content=delta.get("content")
                            ),
                            finish_reason=finish_reason
                        )
                    ]
                )
                yield f"data: {chunk_obj.model_dump_json()}\n\n"

        except Exception as e:
            await wallet_service.release_reservation(user_id, request_id)
            err_envelope = {
                "error": {
                    "code": ErrorCode.PROVIDER_ERROR,
                    "message": f"Streaming terputus: {str(e)}",
                    "request_id": request_id
                }
            }
            yield f"data: {json.dumps(err_envelope)}\n\n"
            yield "data: [DONE]\n\n"
            return

        yield "data: [DONE]\n\n"

        # Settle streaming credits
        latency_ms = int((time.time() - start_time) * 1000)
        final_output_tokens = max(1, output_token_count)
        actual_billed = calculate_token_cost(
            input_tokens=prompt_token_count,
            output_tokens=final_output_tokens,
            input_price_per_million=model_row["input_price_per_million"],
            output_price_per_million=model_row["output_price_per_million"]
        )

        await wallet_service.settle_credits(
            user_id=user_id,
            request_id=request_id,
            actual_billed_credits=actual_billed,
            metadata={
                "model": request.model,
                "input_tokens": prompt_token_count,
                "output_tokens": final_output_tokens,
                "latency_ms": latency_ms,
                "streaming": True
            }
        )

        usage_event_id = f"usg_{uuid.uuid4().hex[:16]}"
        await db.execute(
            """
            INSERT INTO usage_events (id, request_id, user_id, api_key_id, model_id, provider_id,
                                      input_tokens, output_tokens, estimated_cost_minor, billed_credits,
                                      latency_ms, status, error_code, streaming, created_at)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'success', NULL, 1, ?)
            """,
            [
                usage_event_id, request_id, user_id, api_key_id, model_row["id"], model_row["provider_id"],
                prompt_token_count, final_output_tokens, actual_billed, actual_billed, latency_ms, now_iso
            ]
        )

    async def list_user_usage(self, user_id: str, limit: int = 50, offset: int = 0) -> UsageListResponse:
        rows = await db.fetch_all(
            """
            SELECT id, request_id, model_id, provider_id, input_tokens, output_tokens,
                   billed_credits, latency_ms, status, streaming, created_at
            FROM usage_events
            WHERE user_id = ?
            ORDER BY created_at DESC
            LIMIT ? OFFSET ?
            """,
            [user_id, limit, offset]
        )
        total_row = await db.fetch_one("SELECT COUNT(*) as total FROM usage_events WHERE user_id = ?", [user_id])
        total = total_row["total"] if total_row else 0

        items = [
            UsageItem(
                id=r["id"],
                request_id=r["request_id"],
                model_id=r["model_id"],
                provider_id=r["provider_id"],
                input_tokens=r["input_tokens"],
                output_tokens=r["output_tokens"],
                billed_credits=r["billed_credits"],
                latency_ms=r["latency_ms"],
                status=r["status"],
                streaming=bool(r["streaming"]),
                created_at=r["created_at"]
            )
            for r in rows
        ]
        return UsageListResponse(items=items, total=total)


router_service = RouterService()
