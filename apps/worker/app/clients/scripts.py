"""HTTP client for the AI Scripts internal channel.

Two operations:
  * claim_next  — POST /internal/scripts/claim, returns the script dict
                  (status flipped to 'generating') or None on a 204.
  * submit_result — POST /internal/scripts/{id}/result with either a ready
                  body (content + model) or a failed body (error).

Every request is signed with the shared WORKER_INTERNAL_KEY (see signing.py).
"""

from __future__ import annotations

import json
from typing import Any

import httpx

from app.clients.signing import signed_headers


class ScriptsClient:
    """Talks to the Laravel internal AI Scripts endpoints over HMAC."""

    def __init__(
        self,
        base_url: str,
        internal_key: str,
        *,
        timeout: float = 60.0,
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
        """POST /internal/scripts/claim.

        Returns the claimed script payload (the inner data dict) on 200, or
        None on 204 (empty queue). Any other status raises.
        """
        # The endpoint takes no body, but the signing scheme signs the empty
        # string for POST too.
        body = ""
        headers = signed_headers(self._internal_key, body)

        client = self._make_client()
        owns_client = self._client is None
        try:
            response = await client.post(
                "/internal/scripts/claim",
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

    async def submit_result(
        self,
        script_id: int,
        *,
        status: str,
        content: str | None = None,
        model: str | None = None,
        error: str | None = None,
        metadata: dict[str, Any] | None = None,
    ) -> dict[str, Any]:
        """POST /internal/scripts/{id}/result with a signed body.

        For status='ready' supply content (+ optionally model, metadata).
        For status='failed' supply error.
        """
        payload: dict[str, Any] = {"status": status}
        if content is not None:
            payload["content"] = content
        if model is not None:
            payload["model"] = model
        if error is not None:
            payload["error"] = error
        if metadata is not None:
            payload["metadata"] = metadata

        body = json.dumps(payload, separators=(",", ":"), default=str)
        headers = signed_headers(self._internal_key, body)

        client = self._make_client()
        owns_client = self._client is None
        try:
            response = await client.post(
                f"/internal/scripts/{script_id}/result",
                content=body,
                headers=headers,
            )
            response.raise_for_status()
            result: dict[str, Any] = response.json()
            return result
        finally:
            if owns_client:
                await client.aclose()
