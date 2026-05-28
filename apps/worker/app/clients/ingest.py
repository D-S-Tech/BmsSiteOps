"""HTTP client for pushing sync payloads to the Laravel internal API.

Signs each request with the shared WORKER_INTERNAL_KEY using the HMAC scheme
the VerifyWorkerSignature middleware expects:

    payload   = "{timestamp}.{raw_json_body}"
    signature = hex( hmac_sha256(WORKER_INTERNAL_KEY, payload) )

sent as:

    X-Worker-Timestamp: <unix seconds>
    X-Worker-Signature: <hex>
    Content-Type: application/json
"""

from __future__ import annotations

import json
from typing import Any

import httpx

from app.clients.signing import signed_headers


class IngestClient:
    """Posts source sync payloads to the BmsSiteOps Laravel API."""

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

    def _sign(self, body: str) -> dict[str, str]:
        return signed_headers(self._internal_key, body)

    def _make_client(self) -> httpx.AsyncClient:
        if self._client is not None:
            return self._client
        return httpx.AsyncClient(base_url=self._base_url, timeout=self._timeout)

    async def sync_source(self, source_id: int, payload: dict[str, Any]) -> dict[str, Any]:
        """POST /internal/sources/{source_id}/sync with a signed body.

        Returns the parsed JSON response: {source_id, devices_synced, events_ingested}.
        """
        body = json.dumps(payload, separators=(",", ":"), default=str)
        headers = self._sign(body)

        client = self._make_client()
        owns_client = self._client is None
        try:
            response = await client.post(
                f"/internal/sources/{source_id}/sync",
                content=body,
                headers=headers,
            )
            response.raise_for_status()
            result: dict[str, Any] = response.json()
            return result
        finally:
            if owns_client:
                await client.aclose()
