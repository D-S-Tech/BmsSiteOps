"""HTTP client for the AI Site Brief internal channel.

Fetches site context and pushes generated briefs to the Laravel internal API,
signing every request with the shared WORKER_INTERNAL_KEY (see signing.py).
"""

from __future__ import annotations

import json
from typing import Any

import httpx

from app.clients.signing import signed_headers


class BriefClient:
    """Talks to the Laravel internal brief endpoints over the HMAC channel."""

    def __init__(
        self,
        base_url: str,
        internal_key: str,
        *,
        timeout: float = 30.0,
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

    async def fetch_context(self, site_id: int, hours: int = 24) -> dict[str, Any]:
        """GET /internal/sites/{site_id}/brief-context (signed, empty body)."""
        headers = signed_headers(self._internal_key, "")

        client = self._make_client()
        owns_client = self._client is None
        try:
            response = await client.get(
                f"/internal/sites/{site_id}/brief-context",
                params={"hours": hours},
                headers=headers,
            )
            response.raise_for_status()
            result: dict[str, Any] = response.json()
            return result
        finally:
            if owns_client:
                await client.aclose()

    async def push_brief(self, site_id: int, payload: dict[str, Any]) -> dict[str, Any]:
        """POST /internal/sites/{site_id}/briefs with a signed body."""
        body = json.dumps(payload, separators=(",", ":"), default=str)
        headers = signed_headers(self._internal_key, body)

        client = self._make_client()
        owns_client = self._client is None
        try:
            response = await client.post(
                f"/internal/sites/{site_id}/briefs",
                content=body,
                headers=headers,
            )
            response.raise_for_status()
            result: dict[str, Any] = response.json()
            return result
        finally:
            if owns_client:
                await client.aclose()
