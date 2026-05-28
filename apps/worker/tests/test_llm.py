"""Tests for the LLM seam and the LiteLLM client's request/response handling."""

from __future__ import annotations

import httpx
import pytest
import respx

from app.ai.litellm_client import LiteLLMClient, LiteLLMError
from app.ai.llm import FakeLLMClient

BASE = "http://litellm.example.com"


# --- FakeLLMClient -----------------------------------------------------------


async def test_fake_llm_returns_canned_text_and_records_calls() -> None:
    fake = FakeLLMClient(response_text="All nominal.", input_tokens=42, output_tokens=7)

    result = await fake.complete(
        system="sys", user="usr", model="claude-sonnet-4-5", max_tokens=512
    )

    assert result.text == "All nominal."
    assert result.model == "claude-sonnet-4-5"
    assert result.input_tokens == 42
    assert result.output_tokens == 7

    assert len(fake.calls) == 1
    assert fake.calls[0].system == "sys"
    assert fake.calls[0].user == "usr"
    assert fake.calls[0].max_tokens == 512


# --- LiteLLMClient (parsing via mocked transport) ----------------------------


def _openai_response(content: str = "Generated brief.") -> dict:
    return {
        "model": "claude-sonnet-4-5",
        "choices": [{"message": {"role": "assistant", "content": content}}],
        "usage": {"prompt_tokens": 1200, "completion_tokens": 180},
    }


@respx.mock
async def test_litellm_complete_parses_response() -> None:
    route = respx.post(f"{BASE}/chat/completions").mock(
        return_value=httpx.Response(200, json=_openai_response("Site is healthy."))
    )

    client = LiteLLMClient(BASE, "master-key")
    result = await client.complete(system="sys", user="usr", model="claude-sonnet-4-5")

    assert result.text == "Site is healthy."
    assert result.model == "claude-sonnet-4-5"
    assert result.input_tokens == 1200
    assert result.output_tokens == 180
    assert route.called


@respx.mock
async def test_litellm_sends_bearer_and_messages() -> None:
    route = respx.post(f"{BASE}/chat/completions").mock(
        return_value=httpx.Response(200, json=_openai_response())
    )

    client = LiteLLMClient(BASE, "master-key")
    await client.complete(system="SYS", user="USR", model="m", max_tokens=256)

    request = route.calls.last.request
    assert request.headers["Authorization"] == "Bearer master-key"
    import json

    body = json.loads(request.content)
    assert body["model"] == "m"
    assert body["max_tokens"] == 256
    assert body["messages"][0] == {"role": "system", "content": "SYS"}
    assert body["messages"][1] == {"role": "user", "content": "USR"}


@respx.mock
async def test_litellm_raises_on_bad_shape() -> None:
    respx.post(f"{BASE}/chat/completions").mock(
        return_value=httpx.Response(200, json={"nonsense": True})
    )
    client = LiteLLMClient(BASE, "k")
    with pytest.raises(LiteLLMError):
        await client.complete(system="s", user="u", model="m")


@respx.mock
async def test_litellm_raises_on_empty_content() -> None:
    respx.post(f"{BASE}/chat/completions").mock(
        return_value=httpx.Response(200, json=_openai_response("   "))
    )
    client = LiteLLMClient(BASE, "k")
    with pytest.raises(LiteLLMError):
        await client.complete(system="s", user="u", model="m")


@respx.mock
async def test_litellm_raises_on_http_error() -> None:
    respx.post(f"{BASE}/chat/completions").mock(return_value=httpx.Response(500))
    client = LiteLLMClient(BASE, "k")
    with pytest.raises(httpx.HTTPStatusError):
        await client.complete(system="s", user="u", model="m")
