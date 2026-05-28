"""TRMM remediation transport.

HONEST STATUS — read before relying on this module:

  * Request building (URL, headers, JSON shape) is implemented per the public
    TRMM REST API conventions (X-API-KEY auth, /api/agents/{id}/reboot or
    /core/serverreboot for agent restart). It is unit-tested via respx with
    a mocked transport.
  * The LIVE call against a real TRMM server is INTEGRATION-only — it
    requires a reachable TRMM instance and a valid agent identifier, and is
    not exercised in CI. Validate end-to-end against a real TRMM before
    relying on the restart_agent action in production.

Currently supported action kinds:
  - "restart_trmm_agent"  params: {"agent_id": "<uuid>"}
"""

from __future__ import annotations

import httpx

from app.remediation.base import (
    RemediationAction,
    RemediationResult,
    RemediationTransport,
)


class TrmmRemediationTransport(RemediationTransport):
    """Worker -> TRMM REST API remediation transport."""

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
        self._client = client

    def _make_client(self) -> httpx.AsyncClient:
        if self._client is not None:
            return self._client
        return httpx.AsyncClient(base_url=self._base_url, timeout=self._timeout)

    async def execute(self, action: RemediationAction) -> RemediationResult:
        if action.kind == "restart_trmm_agent":
            return await self._restart_agent(action)
        return RemediationResult(
            status="failed",
            message=f"TrmmRemediationTransport: unsupported action {action.kind!r}",
        )

    async def _restart_agent(self, action: RemediationAction) -> RemediationResult:
        agent_id = action.params.get("agent_id")
        if not isinstance(agent_id, str) or not agent_id:
            return RemediationResult(
                status="failed",
                message="restart_trmm_agent requires params.agent_id (string)",
            )

        client = self._make_client()
        owns_client = self._client is None
        try:
            response = await client.post(
                f"/api/agents/{agent_id}/reboot/",
                headers={"X-API-KEY": self._api_key},
            )
            response.raise_for_status()
        except httpx.HTTPStatusError as exc:
            return RemediationResult(
                status="failed",
                message=f"TRMM reboot returned HTTP {exc.response.status_code}",
                result={"agent_id": agent_id, "status_code": exc.response.status_code},
            )
        except httpx.RequestError as exc:
            return RemediationResult(
                status="failed",
                message=f"TRMM transport error: {exc!r}",
                result={"agent_id": agent_id},
            )
        finally:
            if owns_client:
                await client.aclose()

        return RemediationResult(
            status="executed",
            message="TRMM reboot accepted",
            result={"agent_id": agent_id},
        )
