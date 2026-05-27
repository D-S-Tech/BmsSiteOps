"""Tests for the FastAPI app — health endpoint and lifespan."""

from __future__ import annotations

from fastapi.testclient import TestClient

from app import __version__
from app.main import app


def test_health_endpoint_returns_ok() -> None:
    """The /health endpoint must return 200 with a stable payload."""
    with TestClient(app) as client:
        response = client.get("/health")

    assert response.status_code == 200
    body = response.json()
    assert body == {"status": "ok", "version": __version__}


def test_health_endpoint_is_unauthenticated() -> None:
    """/health must not require any auth headers — it's a liveness probe."""
    with TestClient(app) as client:
        response = client.get("/health")

    assert response.status_code == 200
