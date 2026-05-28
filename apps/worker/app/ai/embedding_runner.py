"""EmbeddingRunner — drains the Laravel document embedding queue.

Loop:
  1. POST /internal/documents/claim  -> get the next Pending document (with chunks)
  2. Call EmbeddingClient.embed(chunk_texts) -> EmbeddingResponse
  3. POST /internal/documents/{id}/embeddings with the per-chunk results

Same shape as ScriptRunner and BriefRunner. If anything throws during step
2, the runner submits status='failed' so the document never gets stuck in
Embedding.
"""

from __future__ import annotations

import logging
from typing import Any

from app.ai.embedding import EmbeddingClient
from app.clients.embeddings import EmbeddingsClient

log = logging.getLogger(__name__)


class EmbeddingRunner:
    def __init__(
        self,
        client: EmbeddingsClient,
        embedder: EmbeddingClient,
        *,
        model: str = "ollama/nomic-embed-text",
    ) -> None:
        self._client = client
        self._embedder = embedder
        self._model = model

    async def run_once(self) -> dict[str, Any] | None:
        """Process at most one document. Returns the claimed document
        (with the submit response merged in) or None on empty queue.
        """
        claimed = await self._client.claim_next()
        if claimed is None:
            return None

        document_id = int(claimed["id"])
        chunks = claimed.get("chunks") or []
        log.info(
            "Claimed document id=%s title=%r chunks=%d",
            document_id,
            claimed.get("title"),
            len(chunks),
        )

        if not chunks:
            # Edge case: a document with no chunks (empty content). Mark
            # ready with no chunks to avoid leaving it stuck.
            submitted = await self._client.submit_ready(document_id, [])
            return {"claimed": claimed, "result": submitted, "chunks_embedded": 0}

        try:
            texts = [c["content"] for c in chunks]
            response = await self._embedder.embed(texts, model=self._model)
        except Exception as exc:
            submitted = await self._client.submit_failed(
                document_id, f"Embedding transport error: {exc!r}"
            )
            return {"claimed": claimed, "result": submitted, "chunks_embedded": 0}

        if len(response.embeddings) != len(chunks):
            submitted = await self._client.submit_failed(
                document_id,
                f"Embedding count mismatch: expected {len(chunks)} got {len(response.embeddings)}",
            )
            return {"claimed": claimed, "result": submitted, "chunks_embedded": 0}

        chunk_results = [
            {
                "id": chunks[i]["id"],
                "embedding": response.embeddings[i],
                "embedding_model": response.model,
                # token_count omitted at chunk level — we only have a batch
                # total from most embedding APIs.
            }
            for i in range(len(chunks))
        ]

        submitted = await self._client.submit_ready(document_id, chunk_results)
        return {
            "claimed": claimed,
            "result": submitted,
            "chunks_embedded": len(chunk_results),
        }

    async def drain(self, *, max_items: int = 50) -> int:
        """Repeatedly run_once() until the queue is empty or max_items reached."""
        processed = 0
        while processed < max_items:
            outcome = await self.run_once()
            if outcome is None:
                break
            processed += 1
        return processed
