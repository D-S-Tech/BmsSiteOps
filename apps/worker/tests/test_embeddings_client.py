"""Tests for EmbeddingsClient — request building via respx."""

from __future__ import annotations

import httpx
import pytest
import respx

from app.clients.embeddings import EmbeddingsClient


@pytest.fixture()
def client_kit() -> tuple[EmbeddingsClient, str]:
    base = "https://api.example.com"
    return EmbeddingsClient(base, "test-key"), base


@pytest.mark.asyncio
@respx.mock
async def test_claim_next_returns_data_dict_on_200(
    client_kit: tuple[EmbeddingsClient, str],
) -> None:
    _, base = client_kit
    route = respx.post(f"{base}/internal/documents/claim").mock(
        return_value=httpx.Response(
            200,
            json={
                "data": {
                    "id": 42,
                    "title": "Mech room SOO",
                    "status": "embedding",
                    "chunks": [{"id": 100, "content": "alpha"}],
                }
            },
        )
    )

    async with httpx.AsyncClient(base_url=base) as inner:
        c = EmbeddingsClient(base, "test-key", client=inner)
        result = await c.claim_next()

    assert route.called
    request = route.calls[0].request
    assert request.headers.get("x-worker-signature") is not None
    assert result is not None
    assert result["id"] == 42
    assert result["chunks"][0]["content"] == "alpha"


@pytest.mark.asyncio
@respx.mock
async def test_claim_next_returns_none_on_204(client_kit: tuple[EmbeddingsClient, str]) -> None:
    _, base = client_kit
    respx.post(f"{base}/internal/documents/claim").mock(return_value=httpx.Response(204))

    async with httpx.AsyncClient(base_url=base) as inner:
        c = EmbeddingsClient(base, "test-key", client=inner)
        assert await c.claim_next() is None


@pytest.mark.asyncio
@respx.mock
async def test_submit_ready_posts_chunks_payload(client_kit: tuple[EmbeddingsClient, str]) -> None:
    _, base = client_kit
    route = respx.post(f"{base}/internal/documents/42/embeddings").mock(
        return_value=httpx.Response(200, json={"data": {"id": 42, "status": "ready"}})
    )

    async with httpx.AsyncClient(base_url=base) as inner:
        c = EmbeddingsClient(base, "test-key", client=inner)
        await c.submit_ready(
            42,
            [
                {"id": 100, "embedding": [0.1, 0.2], "embedding_model": "m"},
                {"id": 101, "embedding": [0.3, 0.4], "embedding_model": "m"},
            ],
        )

    import json

    body = json.loads(route.calls[0].request.content)
    assert body["status"] == "ready"
    assert len(body["chunks"]) == 2
    assert body["chunks"][0]["id"] == 100


@pytest.mark.asyncio
@respx.mock
async def test_submit_failed_posts_error_and_omits_chunks(
    client_kit: tuple[EmbeddingsClient, str],
) -> None:
    _, base = client_kit
    route = respx.post(f"{base}/internal/documents/42/embeddings").mock(
        return_value=httpx.Response(200, json={"data": {"id": 42, "status": "failed"}})
    )

    async with httpx.AsyncClient(base_url=base) as inner:
        c = EmbeddingsClient(base, "test-key", client=inner)
        await c.submit_failed(42, "Ollama unreachable")

    import json

    body = json.loads(route.calls[0].request.content)
    assert body == {"status": "failed", "error": "Ollama unreachable"}
    assert "chunks" not in body
