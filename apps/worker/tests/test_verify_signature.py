"""Tests for the inbound HMAC verification dependency."""

from __future__ import annotations

import hashlib
import hmac
import time

import pytest
from fastapi import Depends, FastAPI
from fastapi.testclient import TestClient

from app.auth import verify_worker_signature


@pytest.fixture()
def app_with_protected_route(monkeypatch: pytest.MonkeyPatch) -> tuple[FastAPI, str]:
    """A tiny FastAPI app with one verify-protected route, returning the key."""
    key = "test-secret-key-do-not-use"
    monkeypatch.setenv("WORKER_INTERNAL_KEY", key)
    monkeypatch.setenv("WORKER_MAX_CLOCK_SKEW", "300")

    # Clear cached settings so the env vars take effect.
    from app.config import settings as settings_factory

    settings_factory.cache_clear()  # type: ignore[attr-defined]

    app = FastAPI()

    @app.post("/protected", dependencies=[Depends(verify_worker_signature)])
    async def protected() -> dict[str, str]:
        return {"ok": "yes"}

    return app, key


def _sign(key: str, body: str, timestamp: int | None = None) -> tuple[dict[str, str], str]:
    ts = str(timestamp if timestamp is not None else int(time.time()))
    sig = hmac.new(key.encode(), f"{ts}.".encode() + body.encode(), hashlib.sha256).hexdigest()
    return {"X-Worker-Timestamp": ts, "X-Worker-Signature": sig}, body


def test_accepts_a_correctly_signed_request(
    app_with_protected_route: tuple[FastAPI, str],
) -> None:
    app, key = app_with_protected_route
    headers, body = _sign(key, '{"hello":"world"}')
    with TestClient(app) as c:
        response = c.post("/protected", headers=headers, content=body)
    assert response.status_code == 200
    assert response.json() == {"ok": "yes"}


def test_rejects_missing_signature_header(
    app_with_protected_route: tuple[FastAPI, str],
) -> None:
    app, _ = app_with_protected_route
    with TestClient(app) as c:
        response = c.post("/protected", content="{}", headers={"X-Worker-Timestamp": "0"})
    assert response.status_code == 401
    assert "missing" in response.json()["detail"].lower()


def test_rejects_missing_timestamp_header(
    app_with_protected_route: tuple[FastAPI, str],
) -> None:
    app, _ = app_with_protected_route
    with TestClient(app) as c:
        response = c.post("/protected", content="{}", headers={"X-Worker-Signature": "abc"})
    assert response.status_code == 401


def test_rejects_non_integer_timestamp(
    app_with_protected_route: tuple[FastAPI, str],
) -> None:
    app, _ = app_with_protected_route
    with TestClient(app) as c:
        response = c.post(
            "/protected",
            content="{}",
            headers={"X-Worker-Timestamp": "not-a-number", "X-Worker-Signature": "x" * 64},
        )
    assert response.status_code == 401
    assert "integer" in response.json()["detail"].lower()


def test_rejects_stale_timestamp_outside_clock_skew(
    app_with_protected_route: tuple[FastAPI, str],
) -> None:
    app, key = app_with_protected_route
    headers, body = _sign(key, "{}", timestamp=int(time.time()) - 10_000)
    with TestClient(app) as c:
        response = c.post("/protected", headers=headers, content=body)
    assert response.status_code == 401
    assert "drift" in response.json()["detail"].lower()


def test_rejects_tampered_body(
    app_with_protected_route: tuple[FastAPI, str],
) -> None:
    """Signature was computed against one body; send a different one."""
    app, key = app_with_protected_route
    headers, _ = _sign(key, '{"original":1}')
    with TestClient(app) as c:
        response = c.post("/protected", headers=headers, content='{"tampered":1}')
    assert response.status_code == 401
    assert "signature" in response.json()["detail"].lower()


def test_rejects_wrong_key(
    app_with_protected_route: tuple[FastAPI, str],
) -> None:
    app, _ = app_with_protected_route
    headers, body = _sign("different-key", "{}")
    with TestClient(app) as c:
        response = c.post("/protected", headers=headers, content=body)
    assert response.status_code == 401
