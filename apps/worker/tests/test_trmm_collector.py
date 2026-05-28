"""Tests for the TRMM client and collector using respx-mocked HTTP."""

from __future__ import annotations

import httpx
import pytest
import respx

from app.collectors.base import CollectorConfig, CollectorKind
from app.collectors.trmm import TrmmCollector

TRMM_BASE = "https://trmm.example.com"

AGENTS_FIXTURE = [
    {
        "agent_id": "abc123",
        "hostname": "DC-SERVER-01",
        "operating_system": "Windows Server 2022",
        "plat": "windows",
        "monitoring_type": "server",
        "status": "online",
        "last_seen": "2026-05-27T12:00:00Z",
    },
    {
        "agent_id": "def456",
        "hostname": "WS-RECEPTION",
        "operating_system": "Windows 11",
        "plat": "windows",
        "monitoring_type": "workstation",
        "status": "overdue",
        "last_seen": "2026-05-27T09:30:00Z",
    },
]

ALERTS_FIXTURE = [
    {
        "id": 1,
        "agent_id": "abc123",
        "alert_type": "availability",
        "message": "Agent offline for 10 minutes",
        "severity": "error",
        "created_time": "2026-05-27T12:05:00Z",
        "resolved": False,
    },
    {
        "id": 2,
        "agent_id": "def456",
        "alert_type": "check",
        "message": "Disk C: 92% full",
        "severity": "warning",
        "created_time": "2026-05-27T11:00:00Z",
        "resolved": False,
    },
]


def _config() -> CollectorConfig:
    return CollectorConfig(
        source_id=7,
        tenant_id=3,
        site_id=5,
        kind=CollectorKind.TRMM,
        name="TRMM — Test",
        base_url=TRMM_BASE,
        credentials={"api_token": "fake-key"},
        poll_interval_seconds=60,
    )


@respx.mock
async def test_discover_maps_agents_to_devices() -> None:
    respx.get(f"{TRMM_BASE}/agents/").mock(return_value=httpx.Response(200, json=AGENTS_FIXTURE))

    devices = await TrmmCollector(_config()).discover()

    assert len(devices) == 2

    server = next(d for d in devices if d["external_id"] == "abc123")
    assert server["name"] == "DC-SERVER-01"
    assert server["type"] == "server"
    assert server["status"] == "online"
    assert server["metadata"]["os"] == "Windows Server 2022"

    # 'overdue' normalizes to 'offline'
    ws = next(d for d in devices if d["external_id"] == "def456")
    assert ws["status"] == "offline"
    assert ws["type"] == "workstation"


@respx.mock
async def test_discover_sends_api_key_header() -> None:
    route = respx.get(f"{TRMM_BASE}/agents/").mock(return_value=httpx.Response(200, json=[]))

    await TrmmCollector(_config()).discover()

    assert route.called
    assert route.calls.last.request.headers["X-API-KEY"] == "fake-key"


@respx.mock
async def test_poll_maps_alerts_to_events() -> None:
    respx.get(f"{TRMM_BASE}/alerts/").mock(return_value=httpx.Response(200, json=ALERTS_FIXTURE))

    events = [e async for e in TrmmCollector(_config()).poll()]

    assert len(events) == 2

    first = events[0]
    assert first.device_external_id == "abc123"
    assert first.kind == CollectorKind.TRMM
    assert first.source_id == 7
    assert first.tenant_id == 3
    assert first.site_id == 5
    assert first.metric == "alert"
    assert first.value == "Agent offline for 10 minutes"
    assert first.severity == "critical"  # 'error' -> 'critical'
    assert first.metadata["trmm_alert_id"] == 1

    # 'warning' stays 'warning'
    assert events[1].severity == "warning"


@respx.mock
async def test_poll_skips_alerts_without_agent() -> None:
    respx.get(f"{TRMM_BASE}/alerts/").mock(
        return_value=httpx.Response(
            200,
            json=[
                {
                    "id": 9,
                    "message": "orphan",
                    "severity": "info",
                    "created_time": "2026-05-27T00:00:00Z",
                }
            ],
        )
    )

    events = [e async for e in TrmmCollector(_config()).poll()]
    assert events == []


@respx.mock
async def test_discover_raises_on_http_error() -> None:
    respx.get(f"{TRMM_BASE}/agents/").mock(return_value=httpx.Response(401))

    with pytest.raises(httpx.HTTPStatusError):
        await TrmmCollector(_config()).discover()
