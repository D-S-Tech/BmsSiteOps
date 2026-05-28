"""LiteLLM-backed LLMClient (integration).

Calls LiteLLM's OpenAI-compatible /chat/completions endpoint. LiteLLM proxies
to whichever model is configured (Claude in production, a local Qwen later),
so the worker depends only on this one HTTP shape.

INTEGRATION STATUS: this needs a running LiteLLM proxy to function end to end.
The request building and response parsing are deterministic and unit-tested
with a mocked transport (respx); the live call is exercised in deployment, not
in CI.
"""

from __future__ import annotations

from typing import Any

import httpx

from app.ai.llm import LLMClient, LLMResponse


class LiteLLMError(RuntimeError):
    """Raised when LiteLLM returns an unusable response."""


class LiteLLMClient(LLMClient):
    """Chat completions via a LiteLLM OpenAI-compatible proxy."""

    def __init__(
        self,
        base_url: str,
        master_key: str,
        *,
        timeout: float = 60.0,
        client: httpx.AsyncClient | None = None,
    ) -> None:
        self._base_url = base_url.rstrip("/")
        self._master_key = master_key
        self._timeout = timeout
        self._client = client

    def _make_client(self) -> httpx.AsyncClient:
        if self._client is not None:
            return self._client
        return httpx.AsyncClient(base_url=self._base_url, timeout=self._timeout)

    async def complete(
        self,
        *,
        system: str,
        user: str,
        model: str,
        max_tokens: int = 1024,
    ) -> LLMResponse:
        payload: dict[str, Any] = {
            "model": model,
            "max_tokens": max_tokens,
            "messages": [
                {"role": "system", "content": system},
                {"role": "user", "content": user},
            ],
        }
        headers = {
            "Authorization": f"Bearer {self._master_key}",
            "Content-Type": "application/json",
        }

        client = self._make_client()
        owns_client = self._client is None
        try:
            response = await client.post("/chat/completions", json=payload, headers=headers)
            response.raise_for_status()
            data = response.json()
        finally:
            if owns_client:
                await client.aclose()

        return self._parse(data, model)

    @staticmethod
    def _parse(data: dict[str, Any], requested_model: str) -> LLMResponse:
        try:
            text = data["choices"][0]["message"]["content"]
        except (KeyError, IndexError, TypeError) as exc:
            raise LiteLLMError(f"unexpected LiteLLM response shape: {data!r}") from exc

        if not isinstance(text, str) or not text.strip():
            raise LiteLLMError("LiteLLM returned empty content")

        usage = data.get("usage") or {}
        return LLMResponse(
            text=text,
            model=data.get("model", requested_model),
            input_tokens=int(usage.get("prompt_tokens", 0)),
            output_tokens=int(usage.get("completion_tokens", 0)),
        )
