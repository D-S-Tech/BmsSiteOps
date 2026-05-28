"""LiteLLM-backed real EmbeddingClient.

Posts to the LiteLLM proxy's OpenAI-compatible /embeddings endpoint, which
in turn routes to whatever backend the proxy is configured for:

    model = "ollama/nomic-embed-text"   -> local Ollama (BOLDNJPC, RX 7900 XTX)
    model = "text-embedding-3-small"    -> OpenAI
    model = "voyage-2"                  -> Voyage

The choice is config on the LiteLLM proxy, not code on the worker.

INTEGRATION posture: the request shape (POST /embeddings, model + input,
Bearer auth) is exercised with respx in the test suite; the live call
against a running LiteLLM proxy is not in CI — same as the LLM client and
the TRMM remediation transport.
"""

from __future__ import annotations

import httpx

from app.ai.embedding import EmbeddingClient, EmbeddingResponse


class LiteLlmEmbeddingClient(EmbeddingClient):
    """Real EmbeddingClient — POSTs to a LiteLLM /embeddings endpoint."""

    def __init__(
        self,
        proxy_url: str,
        api_key: str,
        *,
        timeout: float = 60.0,
        client: httpx.AsyncClient | None = None,
    ) -> None:
        self._proxy_url = proxy_url.rstrip("/")
        self._api_key = api_key
        self._timeout = timeout
        self._client = client

    def _make_client(self) -> httpx.AsyncClient:
        if self._client is not None:
            return self._client
        return httpx.AsyncClient(base_url=self._proxy_url, timeout=self._timeout)

    async def embed(self, texts: list[str], *, model: str) -> EmbeddingResponse:
        if not texts:
            return EmbeddingResponse(embeddings=[], model=model, total_tokens=0)

        client = self._make_client()
        owns_client = self._client is None
        try:
            response = await client.post(
                "/embeddings",
                json={"model": model, "input": texts},
                headers={"Authorization": f"Bearer {self._api_key}"},
            )
            response.raise_for_status()
            body = response.json()
        finally:
            if owns_client:
                await client.aclose()

        # OpenAI-compatible shape: data is a list of {embedding, index, object}.
        # Sort by index so we can rely on order even if the upstream
        # rearranges them.
        items = sorted(body.get("data", []), key=lambda d: d.get("index", 0))
        vectors = [item["embedding"] for item in items]

        usage = body.get("usage") or {}
        total_tokens = int(usage.get("total_tokens", 0))

        return EmbeddingResponse(
            embeddings=vectors,
            model=body.get("model", model),
            total_tokens=total_tokens,
        )
