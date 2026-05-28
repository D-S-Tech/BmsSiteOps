"""Tests for the embedding seam — pure FakeEmbeddingClient + LiteLlmEmbeddingClient
request shape via respx (no live LiteLLM proxy).
"""

from __future__ import annotations

import httpx
import pytest
import respx

from app.ai.embedding import EmbeddingResponse, FakeEmbeddingClient
from app.ai.litellm_embedding_client import LiteLlmEmbeddingClient

# ---------------------------------------------------------------------------
# FakeEmbeddingClient
# ---------------------------------------------------------------------------


@pytest.mark.asyncio
async def test_fake_embedding_returns_deterministic_vector_per_text() -> None:
    fake = FakeEmbeddingClient(dimensions=8)

    r1 = await fake.embed(["alpha", "beta"], model="m")
    r2 = await fake.embed(["alpha", "beta"], model="m")

    assert len(r1.embeddings) == 2
    assert len(r1.embeddings[0]) == 8
    assert r1.embeddings == r2.embeddings
    # Different texts -> different vectors.
    assert r1.embeddings[0] != r1.embeddings[1]


@pytest.mark.asyncio
async def test_fake_embedding_records_calls() -> None:
    fake = FakeEmbeddingClient()
    await fake.embed(["a"], model="ollama/nomic-embed-text")
    await fake.embed(["b", "c"], model="ollama/nomic-embed-text")

    assert len(fake.calls) == 2
    assert fake.calls[0].texts == ["a"]
    assert fake.calls[1].texts == ["b", "c"]


@pytest.mark.asyncio
async def test_fake_embedding_can_be_made_to_raise() -> None:
    fake = FakeEmbeddingClient(raises=RuntimeError("boom"))
    with pytest.raises(RuntimeError, match="boom"):
        await fake.embed(["x"], model="m")


# ---------------------------------------------------------------------------
# LiteLlmEmbeddingClient — request shape (respx)
# ---------------------------------------------------------------------------


@pytest.mark.asyncio
@respx.mock
async def test_litellm_embedding_posts_to_embeddings_endpoint() -> None:
    proxy = "https://litellm.example.com"
    route = respx.post(f"{proxy}/embeddings").mock(
        return_value=httpx.Response(
            200,
            json={
                "data": [
                    {"embedding": [0.1, 0.2], "index": 0, "object": "embedding"},
                    {"embedding": [0.3, 0.4], "index": 1, "object": "embedding"},
                ],
                "model": "ollama/nomic-embed-text",
                "usage": {"total_tokens": 42},
            },
        )
    )

    async with httpx.AsyncClient(base_url=proxy) as inner:
        c = LiteLlmEmbeddingClient(proxy, "test-key", client=inner)
        result = await c.embed(["alpha", "beta"], model="ollama/nomic-embed-text")

    assert route.called
    request = route.calls[0].request
    assert request.headers.get("authorization") == "Bearer test-key"

    import json

    body = json.loads(request.content)
    assert body == {"model": "ollama/nomic-embed-text", "input": ["alpha", "beta"]}

    assert isinstance(result, EmbeddingResponse)
    assert result.embeddings == [[0.1, 0.2], [0.3, 0.4]]
    assert result.model == "ollama/nomic-embed-text"
    assert result.total_tokens == 42


@pytest.mark.asyncio
@respx.mock
async def test_litellm_embedding_sorts_by_index() -> None:
    """Upstream can return data in any order; we must sort by index."""
    proxy = "https://litellm.example.com"
    respx.post(f"{proxy}/embeddings").mock(
        return_value=httpx.Response(
            200,
            json={
                "data": [
                    # Out of order on purpose
                    {"embedding": [9.9], "index": 2, "object": "embedding"},
                    {"embedding": [1.1], "index": 0, "object": "embedding"},
                    {"embedding": [5.5], "index": 1, "object": "embedding"},
                ],
                "model": "m",
                "usage": {"total_tokens": 3},
            },
        )
    )

    async with httpx.AsyncClient(base_url=proxy) as inner:
        c = LiteLlmEmbeddingClient(proxy, "k", client=inner)
        result = await c.embed(["a", "b", "c"], model="m")

    assert result.embeddings == [[1.1], [5.5], [9.9]]


@pytest.mark.asyncio
async def test_litellm_embedding_returns_empty_for_empty_input() -> None:
    """No HTTP call should happen for an empty list."""
    async with httpx.AsyncClient(base_url="https://litellm.example.com") as inner:
        c = LiteLlmEmbeddingClient("https://litellm.example.com", "k", client=inner)
        result = await c.embed([], model="m")

    assert result.embeddings == []
    assert result.model == "m"
    assert result.total_tokens == 0
