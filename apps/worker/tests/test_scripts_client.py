"""Tests for ScriptsClient — request building via respx (no live API)."""

from __future__ import annotations

import httpx
import pytest
import respx

from app.clients.scripts import ScriptsClient


@pytest.fixture()
def client_kit() -> tuple[ScriptsClient, str]:
    base = "https://api.example.com"
    return ScriptsClient(base, "test-key"), base


# ---------------------------------------------------------------------------
# claim_next
# ---------------------------------------------------------------------------


@pytest.mark.asyncio
@respx.mock
async def test_claim_next_returns_data_dict_on_200(client_kit: tuple[ScriptsClient, str]) -> None:
    _, base = client_kit
    route = respx.post(f"{base}/internal/scripts/claim").mock(
        return_value=httpx.Response(
            200,
            json={
                "data": {
                    "id": 42,
                    "title": "Restart agent",
                    "language": "python",
                    "status": "generating",
                }
            },
        )
    )

    async with httpx.AsyncClient(base_url=base) as inner:
        client = ScriptsClient(base, "test-key", client=inner)
        result = await client.claim_next()

    assert route.called
    request = route.calls[0].request
    # Signed headers are present.
    assert request.headers.get("x-worker-timestamp") is not None
    assert request.headers.get("x-worker-signature") is not None
    assert result == {
        "id": 42,
        "title": "Restart agent",
        "language": "python",
        "status": "generating",
    }


@pytest.mark.asyncio
@respx.mock
async def test_claim_next_returns_none_on_204(client_kit: tuple[ScriptsClient, str]) -> None:
    _, base = client_kit
    respx.post(f"{base}/internal/scripts/claim").mock(return_value=httpx.Response(204))

    async with httpx.AsyncClient(base_url=base) as inner:
        client = ScriptsClient(base, "test-key", client=inner)
        result = await client.claim_next()

    assert result is None


@pytest.mark.asyncio
@respx.mock
async def test_claim_next_raises_on_5xx(client_kit: tuple[ScriptsClient, str]) -> None:
    _, base = client_kit
    respx.post(f"{base}/internal/scripts/claim").mock(return_value=httpx.Response(503))

    async with httpx.AsyncClient(base_url=base) as inner:
        client = ScriptsClient(base, "test-key", client=inner)
        with pytest.raises(httpx.HTTPStatusError):
            await client.claim_next()


# ---------------------------------------------------------------------------
# submit_result
# ---------------------------------------------------------------------------


@pytest.mark.asyncio
@respx.mock
async def test_submit_ready_includes_content_and_metadata(
    client_kit: tuple[ScriptsClient, str],
) -> None:
    _, base = client_kit
    route = respx.post(f"{base}/internal/scripts/42/result").mock(
        return_value=httpx.Response(200, json={"data": {"id": 42, "status": "ready"}})
    )

    async with httpx.AsyncClient(base_url=base) as inner:
        client = ScriptsClient(base, "test-key", client=inner)
        result = await client.submit_result(
            42,
            status="ready",
            content="print('ok')",
            model="ollama/qwen2.5-coder:32b",
            metadata={"tokens": 100},
        )

    assert result == {"data": {"id": 42, "status": "ready"}}
    request = route.calls[0].request
    import json

    body = json.loads(request.content)
    assert body == {
        "status": "ready",
        "content": "print('ok')",
        "model": "ollama/qwen2.5-coder:32b",
        "metadata": {"tokens": 100},
    }


@pytest.mark.asyncio
@respx.mock
async def test_submit_failed_includes_error_and_omits_content(
    client_kit: tuple[ScriptsClient, str],
) -> None:
    _, base = client_kit
    route = respx.post(f"{base}/internal/scripts/42/result").mock(
        return_value=httpx.Response(200, json={"data": {"id": 42, "status": "failed"}})
    )

    async with httpx.AsyncClient(base_url=base) as inner:
        client = ScriptsClient(base, "test-key", client=inner)
        await client.submit_result(42, status="failed", error="LLM timeout")

    import json

    body = json.loads(route.calls[0].request.content)
    assert body == {"status": "failed", "error": "LLM timeout"}
    assert "content" not in body
