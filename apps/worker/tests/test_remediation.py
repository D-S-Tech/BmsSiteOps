"""Unit tests for the worker-side remediation seam.

Coverage:
  * FakeRemediationTransport records calls and returns the canned result
  * TrmmRemediationTransport — request building only, via respx (no live TRMM)
  * RemediationDispatcher routes by kind and raises on unknown kinds
"""

from __future__ import annotations

import httpx
import pytest
import respx

from app.remediation import (
    FakeRemediationTransport,
    RemediationAction,
    RemediationDispatcher,
    RemediationResult,
    TrmmRemediationTransport,
    UnknownActionError,
)

# ---------------------------------------------------------------------------
# FakeRemediationTransport
# ---------------------------------------------------------------------------


@pytest.mark.asyncio
async def test_fake_transport_records_calls_and_returns_canned_result() -> None:
    canned = RemediationResult(status="executed", message="ok")
    fake = FakeRemediationTransport(result=canned)

    action = RemediationAction(kind="restart_trmm_agent", params={"agent_id": "abc"})
    result = await fake.execute(action)

    assert result is canned
    assert fake.calls == [action]


@pytest.mark.asyncio
async def test_fake_transport_can_be_made_to_raise() -> None:
    fake = FakeRemediationTransport(raises=RuntimeError("boom"))

    with pytest.raises(RuntimeError, match="boom"):
        await fake.execute(RemediationAction(kind="restart_trmm_agent", params={"agent_id": "x"}))


# ---------------------------------------------------------------------------
# TrmmRemediationTransport — request building (respx)
# ---------------------------------------------------------------------------


@pytest.mark.asyncio
@respx.mock
async def test_trmm_restart_agent_builds_correct_request() -> None:
    """Verify URL, method, and X-API-KEY header on a successful reboot."""
    base = "https://trmm.example.com"
    route = respx.post(f"{base}/api/agents/abc-123/reboot/").mock(
        return_value=httpx.Response(200, json={"detail": "scheduled"})
    )

    async with httpx.AsyncClient(base_url=base) as client:
        trmm = TrmmRemediationTransport(base, "test-key", client=client)
        result = await trmm.execute(
            RemediationAction(kind="restart_trmm_agent", params={"agent_id": "abc-123"})
        )

    assert route.called
    request = route.calls[0].request
    assert request.headers.get("x-api-key") == "test-key"
    assert result.status == "executed"
    assert result.result == {"agent_id": "abc-123"}


@pytest.mark.asyncio
@respx.mock
async def test_trmm_returns_failed_on_http_error() -> None:
    base = "https://trmm.example.com"
    respx.post(f"{base}/api/agents/abc-123/reboot/").mock(
        return_value=httpx.Response(503, json={"detail": "unavailable"})
    )

    async with httpx.AsyncClient(base_url=base) as client:
        trmm = TrmmRemediationTransport(base, "key", client=client)
        result = await trmm.execute(
            RemediationAction(kind="restart_trmm_agent", params={"agent_id": "abc-123"})
        )

    assert result.status == "failed"
    assert result.message is not None and "503" in result.message
    assert result.result == {"agent_id": "abc-123", "status_code": 503}


@pytest.mark.asyncio
@respx.mock
async def test_trmm_returns_failed_on_transport_error() -> None:
    base = "https://trmm.example.com"
    respx.post(f"{base}/api/agents/abc/reboot/").mock(
        side_effect=httpx.ConnectError("connection refused")
    )

    async with httpx.AsyncClient(base_url=base) as client:
        trmm = TrmmRemediationTransport(base, "key", client=client)
        result = await trmm.execute(
            RemediationAction(kind="restart_trmm_agent", params={"agent_id": "abc"})
        )

    assert result.status == "failed"
    assert result.message is not None and "ConnectError" in result.message


@pytest.mark.asyncio
async def test_trmm_returns_failed_without_agent_id() -> None:
    """Missing/empty agent_id is a client-side validation failure (no HTTP call)."""
    async with httpx.AsyncClient(base_url="https://trmm.example.com") as client:
        trmm = TrmmRemediationTransport("https://trmm.example.com", "key", client=client)
        result = await trmm.execute(RemediationAction(kind="restart_trmm_agent", params={}))

    assert result.status == "failed"
    assert "agent_id" in (result.message or "")


@pytest.mark.asyncio
async def test_trmm_returns_failed_on_unsupported_action_kind() -> None:
    async with httpx.AsyncClient(base_url="https://trmm.example.com") as client:
        trmm = TrmmRemediationTransport("https://trmm.example.com", "key", client=client)
        result = await trmm.execute(RemediationAction(kind="reboot_universe", params={}))

    assert result.status == "failed"
    assert "unsupported action" in (result.message or "")


# ---------------------------------------------------------------------------
# RemediationDispatcher
# ---------------------------------------------------------------------------


@pytest.mark.asyncio
async def test_dispatcher_routes_to_matching_transport() -> None:
    trmm_fake = FakeRemediationTransport(
        result=RemediationResult(status="executed", message="trmm")
    )
    dispatcher = RemediationDispatcher({"restart_trmm_agent": trmm_fake})

    result = await dispatcher.dispatch(
        RemediationAction(kind="restart_trmm_agent", params={"agent_id": "x"})
    )

    assert result.message == "trmm"
    assert len(trmm_fake.calls) == 1


@pytest.mark.asyncio
async def test_dispatcher_raises_for_unknown_action_kind() -> None:
    dispatcher = RemediationDispatcher({"restart_trmm_agent": FakeRemediationTransport()})

    with pytest.raises(UnknownActionError, match="reboot_universe"):
        await dispatcher.dispatch(RemediationAction(kind="reboot_universe", params={}))


def test_dispatcher_constructor_takes_defensive_copy() -> None:
    """Mutating the source dict after construction must not affect routing."""
    source = {"restart_trmm_agent": FakeRemediationTransport()}
    dispatcher = RemediationDispatcher(source)
    source["other_kind"] = FakeRemediationTransport()

    assert "other_kind" not in dispatcher.supported_kinds
    assert "restart_trmm_agent" in dispatcher.supported_kinds
