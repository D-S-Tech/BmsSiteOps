"""HTTP client for the Tactical RMM REST API.

Wraps the subset of TRMM's API the collector needs: listing agents (devices)
and listing alerts (events). Authentication is via the `X-API-KEY` header.

The client is intentionally thin — it returns parsed JSON and lets the
collector own the normalization into the platform's device/event shapes.
"""

from __future__ import annotations

from typing import Any

import httpx


class TrmmClient:
    """Async client for a single TRMM instance."""

    def __init__(
        self,
        base_url: str,
        api_key: str,
        *,
        timeout: float = 30.0,
        client: httpx.AsyncClient | None = None,
    ) -> None:
        self._base_url = base_url.rstrip("/")
        self._api_key = api_key
        self._timeout = timeout
        # Allow injection of a pre-configured client (tests pass a mock transport).
        self._client = client

    def _make_client(self) -> httpx.AsyncClient:
        if self._client is not None:
            return self._client
        return httpx.AsyncClient(
            base_url=self._base_url,
            headers={"X-API-KEY": self._api_key, "Accept": "application/json"},
            timeout=self._timeout,
        )

    async def _get(self, path: str) -> Any:
        client = self._make_client()
        owns_client = self._client is None
        try:
            response = await client.get(path)
            response.raise_for_status()
            return response.json()
        finally:
            if owns_client:
                await client.aclose()

    async def list_agents(self) -> list[dict[str, Any]]:
        """GET /agents/ — all monitored endpoints.

        TRMM returns a list of agent objects with at least:
        agent_id, hostname, operating_system, plat, monitoring_type, status,
        last_seen.
        """
        data = await self._get("/agents/")
        return data if isinstance(data, list) else data.get("results", [])

    async def list_alerts(self, *, resolved: bool = False) -> list[dict[str, Any]]:
        """GET /alerts/ — alerts, unresolved by default.

        TRMM alert objects include: id, alert_type, message, severity,
        agent_id (or agent), created_time, resolved.
        """
        suffix = "" if resolved else "?resolved=false"
        data = await self._get(f"/alerts/{suffix}")
        return data if isinstance(data, list) else data.get("results", [])
