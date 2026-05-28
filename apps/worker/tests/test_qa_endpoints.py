"""End-to-end tests for the /qa/embed + /qa/answer endpoints.

We stand up a small FastAPI app, mount make_qa_router() with FakeEmbeddingClient
and FakeLLMClient, sign requests with the test HMAC key, and verify the full
request -> response flow.
"""

from __future__ import annotations

import hashlib
import hmac
import json
import time
from typing import Any

import pytest
from fastapi import FastAPI
from fastapi.testclient import TestClient

from app.ai.embedding import FakeEmbeddingClient
from app.ai.llm import FakeLLMClient
from app.qa.endpoints import make_qa_router


@pytest.fixture()
def app_with_qa(
    monkeypatch: pytest.MonkeyPatch,
) -> tuple[FastAPI, str, FakeLLMClient, FakeEmbeddingClient]:
    key = "qa-endpoints-test-key"
    monkeypatch.setenv("WORKER_INTERNAL_KEY", key)
    monkeypatch.setenv("WORKER_MAX_CLOCK_SKEW", "300")
    from app.config import settings as settings_factory

    settings_factory.cache_clear()  # type: ignore[attr-defined]

    embedder = FakeEmbeddingClient(dimensions=8)
    llm = FakeLLMClient()
    app = FastAPI()
    app.include_router(make_qa_router(embedder, llm))
    return app, key, llm, embedder


def _sign(key: str, body: dict[str, Any]) -> tuple[dict[str, str], str]:
    body_str = json.dumps(body, separators=(",", ":"))
    ts = str(int(time.time()))
    sig = hmac.new(key.encode(), f"{ts}.".encode() + body_str.encode(), hashlib.sha256).hexdigest()
    return (
        {
            "X-Worker-Timestamp": ts,
            "X-Worker-Signature": sig,
            "Content-Type": "application/json",
        },
        body_str,
    )


def test_embed_endpoint_returns_embedding_and_model(
    app_with_qa: tuple[FastAPI, str, FakeLLMClient, FakeEmbeddingClient],
) -> None:
    app, key, _, _ = app_with_qa
    headers, body = _sign(key, {"text": "When does AHU-1 start?"})

    with TestClient(app) as c:
        response = c.post("/qa/embed", headers=headers, content=body)

    assert response.status_code == 200, response.text
    payload = response.json()
    assert isinstance(payload["embedding"], list)
    assert len(payload["embedding"]) == 8
    assert payload["model"] == "ollama/nomic-embed-text"


def test_embed_endpoint_passes_through_custom_model(
    app_with_qa: tuple[FastAPI, str, FakeLLMClient, FakeEmbeddingClient],
) -> None:
    app, key, _, embedder = app_with_qa
    headers, body = _sign(key, {"text": "x", "model": "text-embedding-3-small"})

    with TestClient(app) as c:
        c.post("/qa/embed", headers=headers, content=body).raise_for_status()

    assert embedder.calls[-1].model == "text-embedding-3-small"


def test_embed_endpoint_rejects_unsigned_requests(
    app_with_qa: tuple[FastAPI, str, FakeLLMClient, FakeEmbeddingClient],
) -> None:
    app, _, _, _ = app_with_qa
    with TestClient(app) as c:
        response = c.post("/qa/embed", json={"text": "hi"})
    assert response.status_code == 401


def test_answer_endpoint_returns_answer_and_metadata(
    app_with_qa: tuple[FastAPI, str, FakeLLMClient, FakeEmbeddingClient],
) -> None:
    app, key, llm, _ = app_with_qa
    # FakeLLMClient default response is "Test brief." — that's what comes back.
    del llm  # unused in this test

    headers, body = _sign(
        key,
        {
            "question": "When does AHU-1 start?",
            "contexts": [
                {
                    "content": "AHU-1 starts when OAT > 55F.",
                    "document_title": "80 Pine St SOO",
                    "score": 0.92,
                }
            ],
        },
    )

    with TestClient(app) as c:
        response = c.post("/qa/answer", headers=headers, content=body)

    assert response.status_code == 200, response.text
    payload = response.json()
    assert payload["answer"] == "Test brief."
    assert payload["model"] == "claude-sonnet-4-5"
    assert payload["metadata"]["contexts_used"] == 1
    # Token counters present even if zero.
    assert "input_tokens" in payload["metadata"]
    assert "output_tokens" in payload["metadata"]


def test_answer_endpoint_includes_grounding_rule_in_system_prompt(
    app_with_qa: tuple[FastAPI, str, FakeLLMClient, FakeEmbeddingClient],
) -> None:
    """The LLM seam records the calls — we can verify the system prompt
    is exactly the one the operator agreed to.
    """
    app, key, llm, _ = app_with_qa
    headers, body = _sign(key, {"question": "q?", "contexts": []})

    with TestClient(app) as c:
        c.post("/qa/answer", headers=headers, content=body).raise_for_status()

    assert len(llm.calls) == 1
    system = llm.calls[0].system
    assert "Ground every factual claim" in system
    assert "Question: q?" in llm.calls[0].user


def test_answer_endpoint_validates_min_question_length(
    app_with_qa: tuple[FastAPI, str, FakeLLMClient, FakeEmbeddingClient],
) -> None:
    app, key, _, _ = app_with_qa
    headers, body = _sign(key, {"question": "", "contexts": []})

    with TestClient(app) as c:
        response = c.post("/qa/answer", headers=headers, content=body)
    assert response.status_code == 422
