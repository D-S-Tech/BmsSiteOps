"""HTTP client for the Laravel public API.

Used by MCP tools to make on-behalf-of-operator calls into the platform.
Authenticates with a Sanctum personal access token (MCP_API_TOKEN env var,
NOT the worker_internal_key which is HMAC-only). Same auth scheme as
SvelteKit and any other external API client.
"""

from __future__ import annotations

from typing import Any

import httpx


class LaravelClient:
    """Async httpx client wrapping the Laravel REST API."""

    def __init__(
        self,
        base_url: str,
        api_token: str,
        *,
        timeout: float = 60.0,
        client: httpx.AsyncClient | None = None,
    ) -> None:
        self._base_url = base_url.rstrip("/")
        self._api_token = api_token
        self._timeout = timeout
        self._client = client

    def _make_client(self) -> httpx.AsyncClient:
        if self._client is not None:
            return self._client
        return httpx.AsyncClient(base_url=self._base_url, timeout=self._timeout)

    def _headers(self) -> dict[str, str]:
        return {
            "Accept": "application/json",
            "Authorization": f"Bearer {self._api_token}",
        }

    # --- Sites ----------------------------------------------------------------

    async def list_sites(self, per_page: int = 25) -> list[dict[str, Any]]:
        return await self._get_collection("/api/v1/sites", {"per_page": per_page})

    async def site_overview(self, site_id: int) -> dict[str, Any]:
        """Site rollup — combines summary + latest brief in one helper call."""
        client = self._make_client()
        owns_client = self._client is None
        try:
            summary_resp = await client.get(
                f"/api/v1/sites/{site_id}/summary", headers=self._headers()
            )
            summary_resp.raise_for_status()
            summary = summary_resp.json().get("data", {})

            briefs_resp = await client.get(
                f"/api/v1/sites/{site_id}/briefs",
                headers=self._headers(),
                params={"per_page": 1},
            )
            briefs_resp.raise_for_status()
            briefs = briefs_resp.json().get("data", [])
            latest_brief = briefs[0] if briefs else None

            return {"summary": summary, "latest_brief": latest_brief}
        finally:
            if owns_client:
                await client.aclose()

    # --- Q&A ------------------------------------------------------------------

    async def ask(self, question: str, site_id: int | None = None) -> dict[str, Any]:
        payload: dict[str, Any] = {"question": question}
        if site_id is not None:
            payload["site_id"] = site_id
        return await self._post("/api/v1/qa", payload)

    # --- Scripts (Sprint 6 hook) ---------------------------------------------

    async def create_script(self, title: str, prompt: str, language: str) -> dict[str, Any]:
        payload = {"title": title, "prompt": prompt, "language": language}
        return await self._post("/api/v1/scripts", payload)

    # --- helpers --------------------------------------------------------------

    async def _get_collection(
        self, path: str, params: dict[str, Any] | None = None
    ) -> list[dict[str, Any]]:
        client = self._make_client()
        owns_client = self._client is None
        try:
            response = await client.get(path, headers=self._headers(), params=params)
            response.raise_for_status()
            body = response.json()
            data: list[dict[str, Any]] = body.get("data", [])
            return data
        finally:
            if owns_client:
                await client.aclose()

    async def _post(self, path: str, payload: dict[str, Any]) -> dict[str, Any]:
        client = self._make_client()
        owns_client = self._client is None
        try:
            response = await client.post(path, headers=self._headers(), json=payload)
            response.raise_for_status()
            body = response.json()
            data: dict[str, Any] = body.get("data", {})
            return data
        finally:
            if owns_client:
                await client.aclose()
