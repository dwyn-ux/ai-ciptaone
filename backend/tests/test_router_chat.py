import json
import pytest
from app.core.errors import AppError
from app.models.schemas import ChatCompletionMessage, ChatCompletionRequest
from app.services.auth_service import auth_service
from app.services.router_service import router_service, calculate_token_cost
from app.services.wallet_service import wallet_service


def test_calculate_token_cost():
    # 1,000 input tokens at 1,500 IDR/M = ceil(1.5) = 2 IDR
    # 500 output tokens at 3,000 IDR/M = ceil(1.5) = 2 IDR
    # Total = 4 credits
    cost = calculate_token_cost(
        input_tokens=1000,
        output_tokens=500,
        input_price_per_million=1500,
        output_price_per_million=3000,
    )
    assert cost == 4


@pytest.mark.asyncio
async def test_router_models_and_chat():
    # 1. Models list
    models_resp = await router_service.list_openai_models()
    model_ids = [m.id for m in models_resp.data]
    assert "mock/echo-v1" in model_ids

    # 2. Setup user and give credits
    user = await auth_service.register(email="chat_tester@decomu.site", password="ChatPassword2026!")
    await wallet_service.credit_wallet_for_topup(user.id, "INV-CHAT-01", 10000)

    # 3. Non-streaming chat completion with mock provider
    req = ChatCompletionRequest(
        model="mock/echo-v1",
        messages=[
            ChatCompletionMessage(role="user", content="Halo Decomu AI!")
        ],
        stream=False,
        max_tokens=256
    )

    resp = await router_service.chat_completion(user_id=user.id, request=req)
    assert resp.model == "mock/echo-v1"
    assert resp.choices[0].message.content.startswith("[Mock Echo]")
    assert resp.usage.total_tokens > 0

    # Verify wallet was debited
    wallet = await wallet_service.get_wallet(user.id)
    assert wallet.balance_credits < 10000

    # 4. Streaming chat completion
    stream_req = ChatCompletionRequest(
        model="mock/echo-v1",
        messages=[
            ChatCompletionMessage(role="user", content="Jelaskan apa itu AI Router.")
        ],
        stream=True,
        max_tokens=256
    )

    chunks = []
    async for chunk_str in router_service.chat_completion_stream(user_id=user.id, request=stream_req):
        chunks.append(chunk_str)

    assert len(chunks) > 2
    assert any("[Mock Echo]" in c for c in chunks)
    assert chunks[-1] == "data: [DONE]\n\n"

    # 5. Unavailable / disabled model rejection
    disabled_req = ChatCompletionRequest(
        model="non-existent/model-id",
        messages=[ChatCompletionMessage(role="user", content="Hi")],
        stream=False
    )
    with pytest.raises(AppError) as exc_info:
        await router_service.chat_completion(user_id=user.id, request=disabled_req)
    assert exc_info.value.code == "model_unavailable"
