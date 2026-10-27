from typing import Optional
from fastapi import APIRouter, Depends, Header, Request, status
from fastapi.responses import StreamingResponse
from app.config import settings
from app.core.rate_limit import limiter
from app.models.schemas import ChatCompletionRequest, ChatCompletionResponse, OpenAIModelListResponse
from app.routers.deps import get_auth_for_completion
from app.services.router_service import router_service

router = APIRouter(prefix="/v1", tags=["OpenAI Compatible Gateway"])


@router.get("/models", response_model=OpenAIModelListResponse)
async def list_models():
    """Returns list of active models in OpenAI-compatible format."""
    return await router_service.list_openai_models()


@router.post("/chat/completions")
async def chat_completions(
    req: ChatCompletionRequest,
    request: Request,
    auth_data: tuple[str, Optional[str]] = Depends(get_auth_for_completion),
):
    """
    OpenAI-compatible chat completion endpoint.
    Accepts Bearer <api_key> header or authenticated browser session cookie.
    Supports non-streaming JSON and streaming text/event-stream (SSE).
    """
    user_id, api_key_id = auth_data

    # Rate limiting per user / API key
    rate_limit_key = f"chat_usr_{user_id}"
    limiter.check_rate_limit(rate_limit_key, max_requests=settings.RATE_LIMIT_CHAT_PER_MINUTE)

    if req.stream:
        stream_generator = router_service.chat_completion_stream(
            user_id=user_id,
            request=req,
            api_key_id=api_key_id,
        )
        return StreamingResponse(
            stream_generator,
            media_type="text/event-stream",
            headers={
                "Cache-Control": "no-cache",
                "Connection": "keep-alive",
                "X-Accel-Buffering": "no",
            },
        )
    else:
        completion_resp = await router_service.chat_completion(
            user_id=user_id,
            request=req,
            api_key_id=api_key_id,
        )
        return completion_resp
