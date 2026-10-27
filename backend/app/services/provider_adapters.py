import abc
import asyncio
import json
import time
from typing import AsyncGenerator, Dict, Any, List, Optional
import httpx
from app.core.errors import AppError, ErrorCode


class UpstreamUsage:
    def __init__(self, prompt_tokens: int, completion_tokens: int):
        self.prompt_tokens = prompt_tokens
        self.completion_tokens = completion_tokens
        self.total_tokens = prompt_tokens + completion_tokens


class BaseProviderAdapter(abc.ABC):
    @abc.abstractmethod
    async def chat_completion(
        self,
        model_name: str,
        messages: List[Dict[str, str]],
        max_tokens: int,
        temperature: float,
    ) -> Dict[str, Any]:
        """Performs non-streaming completion."""
        pass

    @abc.abstractmethod
    async def chat_completion_stream(
        self,
        model_name: str,
        messages: List[Dict[str, str]],
        max_tokens: int,
        temperature: float,
    ) -> AsyncGenerator[Dict[str, Any], None]:
        """Yields streaming chunks."""
        pass


class MockProviderAdapter(BaseProviderAdapter):
    """
    Mock AI Provider for development, unit testing, and sandbox verification.
    Produces deterministic echo responses with simulated token counts and latencies.
    """

    async def chat_completion(
        self,
        model_name: str,
        messages: List[Dict[str, str]],
        max_tokens: int,
        temperature: float,
    ) -> Dict[str, Any]:
        last_message = messages[-1]["content"] if messages else "Halo"
        reply_content = f"[Mock Echo] Menerima pesan: \"{last_message}\". Model: {model_name}."

        prompt_tokens = sum(len(m["content"].split()) for m in messages) * 2 + 5
        completion_tokens = len(reply_content.split()) * 2 + 2

        return {
            "content": reply_content,
            "finish_reason": "stop",
            "prompt_tokens": prompt_tokens,
            "completion_tokens": completion_tokens,
        }

    async def chat_completion_stream(
        self,
        model_name: str,
        messages: List[Dict[str, str]],
        max_tokens: int,
        temperature: float,
    ) -> AsyncGenerator[Dict[str, Any], None]:
        last_message = messages[-1]["content"] if messages else "Halo"
        full_text = f"[Mock Echo] Menerima pesan: \"{last_message}\". Streaming aktif."
        words = full_text.split(" ")

        for i, word in enumerate(words):
            chunk_text = word if i == 0 else f" {word}"
            yield {
                "delta": {"role": "assistant" if i == 0 else None, "content": chunk_text},
                "finish_reason": None
            }
            await asyncio.sleep(0.01)  # Simulate small network chunk delay

        yield {
            "delta": {},
            "finish_reason": "stop",
            "usage": {
                "prompt_tokens": len(last_message.split()) * 2 + 5,
                "completion_tokens": len(words) * 2 + 2
            }
        }


class OpenAICompatibleAdapter(BaseProviderAdapter):
    """
    Adapter for upstream OpenAI-compatible endpoints (such as OpenRouter, OpenAI, Groq).
    """

    def __init__(self, base_url: str, api_key: str):
        self.base_url = base_url.rstrip("/")
        self.api_key = api_key

    async def chat_completion(
        self,
        model_name: str,
        messages: List[Dict[str, str]],
        max_tokens: int,
        temperature: float,
    ) -> Dict[str, Any]:
        if not self.api_key:
            raise AppError(
                code=ErrorCode.MODEL_UNAVAILABLE,
                message=f"Kredensial upstream provider untuk model '{model_name}' belum dikonfigurasi.",
                status_code=503
            )

        headers = {
            "Authorization": f"Bearer {self.api_key}",
            "Content-Type": "application/json"
        }
        payload = {
            "model": model_name,
            "messages": messages,
            "max_tokens": max_tokens,
            "temperature": temperature,
            "stream": False
        }

        async with httpx.AsyncClient(timeout=60.0) as client:
            try:
                resp = await client.post(f"{self.base_url}/chat/completions", json=payload, headers=headers)
            except httpx.TimeoutException:
                raise AppError(code=ErrorCode.PROVIDER_TIMEOUT, message="Upstream AI provider timeout.", status_code=504)
            except Exception as e:
                raise AppError(code=ErrorCode.PROVIDER_ERROR, message=f"Koneksi upstream gagal: {str(e)}", status_code=502)

        if resp.status_code != 200:
            raise AppError(
                code=ErrorCode.PROVIDER_ERROR,
                message=f"Upstream provider error: HTTP {resp.status_code}",
                status_code=502,
                details={"upstream_status": resp.status_code}
            )

        data = resp.json()
        choice = data.get("choices", [{}])[0]
        usage = data.get("usage", {})

        return {
            "content": choice.get("message", {}).get("content", ""),
            "finish_reason": choice.get("finish_reason", "stop"),
            "prompt_tokens": usage.get("prompt_tokens", 10),
            "completion_tokens": usage.get("completion_tokens", 10),
        }

    async def chat_completion_stream(
        self,
        model_name: str,
        messages: List[Dict[str, str]],
        max_tokens: int,
        temperature: float,
    ) -> AsyncGenerator[Dict[str, Any], None]:
        if not self.api_key:
            raise AppError(
                code=ErrorCode.MODEL_UNAVAILABLE,
                message=f"Kredensial upstream provider belum dikonfigurasi.",
                status_code=503
            )

        headers = {
            "Authorization": f"Bearer {self.api_key}",
            "Content-Type": "application/json"
        }
        payload = {
            "model": model_name,
            "messages": messages,
            "max_tokens": max_tokens,
            "temperature": temperature,
            "stream": True
        }

        client = httpx.AsyncClient(timeout=60.0)
        try:
            async with client.stream("POST", f"{self.base_url}/chat/completions", json=payload, headers=headers) as resp:
                if resp.status_code != 200:
                    raise AppError(
                        code=ErrorCode.PROVIDER_ERROR,
                        message=f"Upstream provider streaming error: HTTP {resp.status_code}",
                        status_code=502
                    )

                async for line in resp.aiter_lines():
                    if not line:
                        continue
                    if line.startswith("data: "):
                        raw_data = line[6:].strip()
                        if raw_data == "[DONE]":
                            break
                        try:
                            chunk = json.loads(raw_data)
                            choice = chunk.get("choices", [{}])[0]
                            delta = choice.get("delta", {})
                            finish_reason = choice.get("finish_reason")
                            usage = chunk.get("usage")
                            yield {
                                "delta": delta,
                                "finish_reason": finish_reason,
                                "usage": usage
                            }
                        except Exception:
                            continue
        finally:
            await client.aclose()
