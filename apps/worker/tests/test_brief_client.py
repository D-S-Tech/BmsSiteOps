"""Tests for BriefClient — HMAC-signed context fetch and brief push."""

from __future__ import annotations

import json

import httpx
import respx

from app.clients.brief import BriefClient

BASE = "http://api.example.com"
KEY = "internal-secret"


@respx.mock
async def test_fetch_context_signs_and_parses() -> None:
    payload = {"site": {"id": 4, "name": "80 Pine"}, "events": {"critical": 2}}
    route = respx.get(f"{BASE}/internal/sites/4/brief-context").mock(
        return_value=httpx.Response(200, json=payload)
    )

    client = BriefClient(BASE, KEY)
    result = await client.fetch_context(4, hours=12)

    assert result["site"]["name"] == "80 Pine"

    request = route.calls.last.request
    assert request.headers["X-Worker-Timestamp"]
    assert request.headers["X-Worker-Signature"]
    assert request.url.params["hours"] == "12"


@respx.mock
async def test_push_brief_signs_body_and_parses() -> None:
    route = respx.post(f"{BASE}/internal/sites/4/briefs").mock(
        return_value=httpx.Response(201, json={"data": {"id": 9, "model": "m"}})
    )

    client = BriefClient(BASE, KEY)
    result = await client.push_brief(4, {"summary": "ok", "model": "m"})

    assert result["data"]["id"] == 9

    request = route.calls.last.request
    assert request.headers["X-Worker-Signature"]
    # The signed body is the exact JSON sent.
    body = json.loads(request.content)
    assert body == {"summary": "ok", "model": "m"}


@respx.mock
async def test_push_brief_raises_on_error() -> None:
    respx.post(f"{BASE}/internal/sites/4/briefs").mock(
        return_value=httpx.Response(422, json={"message": "invalid"})
    )
    client = BriefClient(BASE, KEY)
    try:
        await client.push_brief(4, {"summary": "x"})
    except httpx.HTTPStatusError as exc:
        assert exc.response.status_code == 422
    else:  # pragma: no cover
        raise AssertionError("expected HTTPStatusError")
