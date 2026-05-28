"""HTTP client for the RAG documents internal channel.

Two operations:
  * claim_next  — POST /internal/documents/claim, returns the document dict
                  (with chunks embedded) or None on 204.
  * submit_ready / submit_failed — POST /internal/documents/{id}/embeddings
                  with the result payload.

All requests are signed with WORKER_INTERNAL_KEY (see signing.py).
"""

from __future__ import annotations

import json
from typing import Any

import httpx

from app.clients.signing import signed_headers


class EmbeddingsClient:
    """Talks to the Laravel internal RAG endpoints over HMAC."""

    def __init__(
        self,
        base_url: str,
        internal_key: str,
        *,
        timeout: float = 120.0,
        client: httpx.AsyncClient | None = None,
    ) -> None:
        self._base_url = base_url.rstrip("/")
        self._internal_key = internal_key
        self._timeout = timeout
        self._client = client

    def _make_client(self) -> httpx.AsyncClient:
        if self._client is not None:
            return self._client
        return httpx.AsyncClient(base_url=self._base_url, timeout=self._timeout)

    async def claim_next(self) -> dict[str, Any] | None:
        body = ""
        headers = signed_headers(self._internal_key, body)

        client = self._make_client()
        owns_client = self._client is None
        try:
            response = await client.post(
                "/internal/documents/claim",
                content=body,
                headers=headers,
            )
            if response.status_code == 204:
                return None
            response.raise_for_status()
            data = response.json()
            payload: dict[str, Any] = data["data"]
            return payload
        finally:
            if owns_client:
                await client.aclose()

    async def submit_ready(
        self,
        document_id: int,
        chunk_results: list[dict[str, Any]],
    ) -> dict[str, Any]:
        """Submit successful embeddings.

        Each chunk_results entry is {id, embedding, embedding_model,
        token_count?} — see SubmitDocumentEmbeddingsRequest on the Laravel side.
        """
        payload = {"status": "ready", "chunks": chunk_results}
        return await self._submit(document_id, payload)

    async def submit_failed(self, document_id: int, error: str) -> dict[str, Any]:
        return await self._submit(document_id, {"status": "failed", "error": error})

    async def _submit(self, document_id: int, payload: dict[str, Any]) -> dict[str, Any]:
        body = json.dumps(payload, separators=(",", ":"), default=str)
        headers = signed_headers(self._internal_key, body)

        client = self._make_client()
        owns_client = self._client is None
        try:
            response = await client.post(
                f"/internal/documents/{document_id}/embeddings",
                content=body,
                headers=headers,
            )
            response.raise_for_status()
            result: dict[str, Any] = response.json()
            return result
        finally:
            if owns_client:
                await client.aclose()
