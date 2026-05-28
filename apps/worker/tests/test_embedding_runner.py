"""Tests for EmbeddingRunner — orchestration with fake client + fake embedder."""

from __future__ import annotations

from typing import Any

import pytest

from app.ai.embedding import FakeEmbeddingClient
from app.ai.embedding_runner import EmbeddingRunner
from app.clients.embeddings import EmbeddingsClient


class _FakeEmbeddingsClient(EmbeddingsClient):
    """In-memory EmbeddingsClient — yields canned claims, records submits."""

    def __init__(self, claims: list[dict[str, Any] | None]) -> None:
        # Skip the real __init__; no HTTP.
        self._claims = list(claims)
        self.ready_submits: list[tuple[int, list[dict[str, Any]]]] = []
        self.failed_submits: list[tuple[int, str]] = []

    async def claim_next(self) -> dict[str, Any] | None:
        if not self._claims:
            return None
        return self._claims.pop(0)

    async def submit_ready(
        self, document_id: int, chunk_results: list[dict[str, Any]]
    ) -> dict[str, Any]:
        self.ready_submits.append((document_id, chunk_results))
        return {"data": {"id": document_id, "status": "ready"}}

    async def submit_failed(self, document_id: int, error: str) -> dict[str, Any]:
        self.failed_submits.append((document_id, error))
        return {"data": {"id": document_id, "status": "failed"}}


def _claimed_document(**overrides: Any) -> dict[str, Any]:
    base = {
        "id": 7,
        "title": "Test doc",
        "status": "embedding",
        "chunks": [
            {"id": 100, "position": 0, "content": "alpha"},
            {"id": 101, "position": 1, "content": "beta"},
        ],
    }
    base.update(overrides)
    return base


@pytest.mark.asyncio
async def test_run_once_returns_none_when_queue_empty() -> None:
    client = _FakeEmbeddingsClient(claims=[None])
    runner = EmbeddingRunner(client, FakeEmbeddingClient())

    result = await runner.run_once()

    assert result is None
    assert client.ready_submits == []
    assert client.failed_submits == []


@pytest.mark.asyncio
async def test_run_once_embeds_and_submits_ready() -> None:
    client = _FakeEmbeddingsClient(claims=[_claimed_document(id=7)])
    runner = EmbeddingRunner(client, FakeEmbeddingClient(dimensions=8), model="m")

    outcome = await runner.run_once()

    assert outcome is not None
    assert outcome["chunks_embedded"] == 2

    assert len(client.ready_submits) == 1
    doc_id, chunks = client.ready_submits[0]
    assert doc_id == 7
    assert len(chunks) == 2
    assert chunks[0]["id"] == 100
    assert chunks[1]["id"] == 101
    assert len(chunks[0]["embedding"]) == 8
    assert chunks[0]["embedding_model"] == "m"
    # Same text -> same vector (FakeEmbeddingClient is deterministic).
    assert chunks[0]["embedding"] != chunks[1]["embedding"]


@pytest.mark.asyncio
async def test_run_once_submits_failed_when_embedder_raises() -> None:
    client = _FakeEmbeddingsClient(claims=[_claimed_document(id=8)])
    runner = EmbeddingRunner(
        client,
        FakeEmbeddingClient(raises=RuntimeError("connection refused")),
        model="m",
    )

    outcome = await runner.run_once()

    assert outcome is not None
    assert outcome["chunks_embedded"] == 0
    assert len(client.ready_submits) == 0
    assert len(client.failed_submits) == 1
    doc_id, error = client.failed_submits[0]
    assert doc_id == 8
    assert "connection refused" in error


@pytest.mark.asyncio
async def test_run_once_handles_document_with_no_chunks() -> None:
    """Edge case: a document with no chunks should be flushed to ready with no
    embeddings rather than left stuck in Embedding.
    """
    client = _FakeEmbeddingsClient(claims=[_claimed_document(id=9, chunks=[])])
    runner = EmbeddingRunner(client, FakeEmbeddingClient())

    outcome = await runner.run_once()

    assert outcome is not None
    assert outcome["chunks_embedded"] == 0
    assert len(client.ready_submits) == 1
    doc_id, chunks = client.ready_submits[0]
    assert doc_id == 9
    assert chunks == []


@pytest.mark.asyncio
async def test_run_once_submits_failed_on_embedding_count_mismatch() -> None:
    """A misbehaving transport returning fewer/more vectors than inputs."""

    from app.ai.embedding import EmbeddingClient, EmbeddingResponse

    class _MisbehavingEmbedder(EmbeddingClient):
        async def embed(self, texts: list[str], *, model: str) -> EmbeddingResponse:
            # Return only one vector for two inputs.
            return EmbeddingResponse(embeddings=[[0.1, 0.2]], model=model, total_tokens=1)

    client = _FakeEmbeddingsClient(claims=[_claimed_document(id=10)])
    runner = EmbeddingRunner(client, _MisbehavingEmbedder())

    outcome = await runner.run_once()
    assert outcome is not None
    assert outcome["chunks_embedded"] == 0
    assert len(client.failed_submits) == 1
    assert "mismatch" in client.failed_submits[0][1]


@pytest.mark.asyncio
async def test_drain_processes_until_queue_empty() -> None:
    client = _FakeEmbeddingsClient(
        claims=[
            _claimed_document(id=1),
            _claimed_document(id=2),
            _claimed_document(id=3),
            None,
        ]
    )
    runner = EmbeddingRunner(client, FakeEmbeddingClient())

    processed = await runner.drain()
    assert processed == 3
    assert [doc_id for doc_id, _ in client.ready_submits] == [1, 2, 3]
