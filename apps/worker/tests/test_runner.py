"""Tests for the ingest client (HMAC signing) and the sync runner."""

from __future__ import annotations

import hashlib
import hmac

import httpx
import pytest
import respx

from app.clients.ingest import IngestClient
from app.collectors.base import CollectorConfig, CollectorKind
from app.runner import SyncRunner, build_collector

API_BASE = "https://ops.example.com"
TRMM_BASE = "https://trmm.example.com"
INTERNAL_KEY = "shared-secret"


def _trmm_config() -> CollectorConfig:
    return CollectorConfig(
        source_id=42,
        tenant_id=1,
        site_id=2,
        kind=CollectorKind.TRMM,
        name="TRMM",
        base_url=TRMM_BASE,
        credentials={"api_token": "k"},
        poll_interval_seconds=60,
    )


# --- IngestClient ------------------------------------------------------------


@respx.mock
async def test_ingest_client_signs_request_correctly() -> None:
    route = respx.post(f"{API_BASE}/internal/sources/42/sync").mock(
        return_value=httpx.Response(
            200, json={"source_id": 42, "devices_synced": 0, "events_ingested": 0}
        )
    )

    client = IngestClient(API_BASE, INTERNAL_KEY)
    await client.sync_source(42, {"status": "ok", "devices": [], "events": []})

    assert route.called
    request = route.calls.last.request

    timestamp = request.headers["X-Worker-Timestamp"]
    signature = request.headers["X-Worker-Signature"]
    body = request.content.decode()

    expected = hmac.new(
        INTERNAL_KEY.encode(), f"{timestamp}.{body}".encode(), hashlib.sha256
    ).hexdigest()
    assert hmac.compare_digest(expected, signature)


@respx.mock
async def test_ingest_client_returns_counts() -> None:
    respx.post(f"{API_BASE}/internal/sources/42/sync").mock(
        return_value=httpx.Response(
            200, json={"source_id": 42, "devices_synced": 3, "events_ingested": 5}
        )
    )

    client = IngestClient(API_BASE, INTERNAL_KEY)
    result = await client.sync_source(42, {"status": "ok"})

    assert result["devices_synced"] == 3
    assert result["events_ingested"] == 5


# --- build_collector ---------------------------------------------------------


def test_build_collector_returns_correct_type() -> None:
    collector = build_collector(_trmm_config())
    assert collector.kind == CollectorKind.TRMM


# --- SyncRunner --------------------------------------------------------------


@respx.mock
async def test_runner_collects_and_pushes() -> None:
    respx.get(f"{TRMM_BASE}/agents/").mock(
        return_value=httpx.Response(
            200,
            json=[
                {
                    "agent_id": "a1",
                    "hostname": "HOST-1",
                    "monitoring_type": "server",
                    "status": "online",
                }
            ],
        )
    )
    respx.get(f"{TRMM_BASE}/alerts/").mock(
        return_value=httpx.Response(
            200,
            json=[
                {
                    "id": 1,
                    "agent_id": "a1",
                    "message": "boom",
                    "severity": "error",
                    "created_time": "2026-05-27T00:00:00Z",
                }
            ],
        )
    )
    sync_route = respx.post(f"{API_BASE}/internal/sources/42/sync").mock(
        return_value=httpx.Response(
            200, json={"source_id": 42, "devices_synced": 1, "events_ingested": 1}
        )
    )

    runner = SyncRunner(IngestClient(API_BASE, INTERNAL_KEY))
    result = await runner.run_once(_trmm_config())

    assert result["devices_synced"] == 1
    assert result["events_ingested"] == 1

    # Verify the payload we pushed contained the normalized device + event.
    import json

    pushed = json.loads(sync_route.calls.last.request.content.decode())
    assert pushed["status"] == "ok"
    assert pushed["devices"][0]["external_id"] == "a1"
    assert pushed["events"][0]["device_external_id"] == "a1"
    assert pushed["events"][0]["severity"] == "critical"


@respx.mock
async def test_runner_pushes_error_status_on_collector_failure() -> None:
    # TRMM returns 500 -> collector raises -> runner reports error status.
    respx.get(f"{TRMM_BASE}/agents/").mock(return_value=httpx.Response(500))
    error_route = respx.post(f"{API_BASE}/internal/sources/42/sync").mock(
        return_value=httpx.Response(
            200, json={"source_id": 42, "devices_synced": 0, "events_ingested": 0}
        )
    )

    runner = SyncRunner(IngestClient(API_BASE, INTERNAL_KEY))

    with pytest.raises(httpx.HTTPStatusError):
        await runner.run_once(_trmm_config())

    import json

    pushed = json.loads(error_route.calls.last.request.content.decode())
    assert pushed["status"] == "error"
    assert "error" in pushed
