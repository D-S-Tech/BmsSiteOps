"""Live LiteLLM proxy integration tests.

These hit a REAL running LiteLLM proxy. They prove the LLM + embedding seams
built in Sprints 4, 6, and 7.2 actually round-trip real bytes — not just the
respx-mocked request shapes that the unit suite verifies.

Required env vars (any missing -> pytest skip):
    LITELLM_BASE_URL    e.g. http://10.0.0.42:4000
    LITELLM_MASTER_KEY  the proxy's master key
    LIVE_TESTS=1        flag (set by `make worker-test-integration`)

Optional model overrides:
    LIVE_EMBEDDING_MODEL   default: ollama/nomic-embed-text
    LIVE_LLM_MODEL         default: claude-haiku-4-5 (cheapest Claude for smoke)
"""

from __future__ import annotations

import os

import httpx
import pytest

from app.ai.litellm_client import LiteLLMClient
from app.ai.litellm_embedding_client import LiteLlmEmbeddingClient

pytestmark = pytest.mark.live


# ---------------------------------------------------------------------------
# Fixtures
# ---------------------------------------------------------------------------


def _env_or_skip(*names: str) -> tuple[str, ...]:
    missing = [n for n in names if not os.environ.get(n)]
    if missing:
        pytest.skip(f"missing env var(s): {', '.join(missing)}")
    return tuple(os.environ[n] for n in names)


@pytest.fixture(scope="module")
def proxy() -> tuple[str, str]:
    base_url, key = _env_or_skip("LITELLM_BASE_URL", "LITELLM_MASTER_KEY")
    return base_url, key


@pytest.fixture()
def embed_model() -> str:
    return os.environ.get("LIVE_EMBEDDING_MODEL", "ollama/nomic-embed-text")


@pytest.fixture()
def llm_model() -> str:
    return os.environ.get("LIVE_LLM_MODEL", "claude-haiku-4-5")


# ---------------------------------------------------------------------------
# Tests
# ---------------------------------------------------------------------------


@pytest.mark.asyncio
async def test_litellm_health_endpoint_is_reachable(
    proxy: tuple[str, str],
) -> None:
    """Smallest possible sanity check — the proxy itself is up."""
    base_url, _ = proxy
    async with httpx.AsyncClient(base_url=base_url, timeout=10.0) as c:
        response = await c.get("/health/liveliness")
    assert response.status_code == 200


@pytest.mark.asyncio
async def test_embedding_round_trip_returns_a_real_vector(
    proxy: tuple[str, str], embed_model: str
) -> None:
    """Proves the LiteLlmEmbeddingClient + the live proxy + the configured
    embedding backend (default: Ollama nomic-embed-text) work end to end.

    Asserts:
      - exactly one vector for one input
      - non-empty vector
      - all entries are floats
      - identical input -> identical vector (deterministic embedding)
    """
    base_url, key = proxy
    client = LiteLlmEmbeddingClient(base_url, key)

    result = await client.embed(["AHU-1 controls"], model=embed_model)

    assert len(result.embeddings) == 1
    vec = result.embeddings[0]
    assert len(vec) > 0
    assert all(isinstance(x, float) for x in vec)

    # Determinism: same input twice -> same vector.
    result2 = await client.embed(["AHU-1 controls"], model=embed_model)
    assert result.embeddings[0] == result2.embeddings[0]


@pytest.mark.asyncio
async def test_embedding_batch_returns_distinct_vectors(
    proxy: tuple[str, str], embed_model: str
) -> None:
    """Two different texts must yield two distinct vectors."""
    base_url, key = proxy
    client = LiteLlmEmbeddingClient(base_url, key)

    result = await client.embed(
        ["chilled water supply temperature", "outdoor air enthalpy"],
        model=embed_model,
    )
    assert len(result.embeddings) == 2
    assert result.embeddings[0] != result.embeddings[1]


@pytest.mark.asyncio
async def test_llm_completion_returns_real_text(proxy: tuple[str, str], llm_model: str) -> None:
    """Proves the LLMClient + live proxy + the configured LLM backend work
    end to end. Uses the cheapest Claude model by default to keep cost low.
    """
    base_url, key = proxy
    client = LiteLLMClient(base_url, key)

    response = await client.complete(
        system="You are a terse HVAC assistant. Reply in fewer than 10 words.",
        user="What temperature do most chilled water plants supply at?",
        model=llm_model,
        max_tokens=64,
    )

    assert response.text.strip(), "LLM returned empty text"
    assert response.input_tokens > 0
    assert response.output_tokens > 0
    # Sanity: the text should mention degrees or numbers, not pure refusal.
    text_lower = response.text.lower()
    assert any(t in text_lower for t in ["f", "c", "degree", "44", "45", "42"])
