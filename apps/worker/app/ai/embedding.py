"""Embedding client seam — parallel to the LLM seam (app/ai/llm.py).

The embedding runner depends only on the EmbeddingClient interface, so its
batching + submission logic is fully unit-tested with FakeEmbeddingClient.
The real LiteLLM-backed implementation lives in litellm_embedding_client.py
and is an integration concern (it needs a running LiteLLM proxy with an
embedding model configured — typically ollama/nomic-embed-text on the local
AI workstation).
"""

from __future__ import annotations

import hashlib
from abc import ABC, abstractmethod
from dataclasses import dataclass


@dataclass(frozen=True)
class EmbeddingResponse:
    """The result of one batch embed() call.

    embeddings[i] is the vector for inputs[i] — order must be preserved by
    every transport.
    """

    embeddings: list[list[float]]
    model: str
    total_tokens: int = 0


class EmbeddingClient(ABC):
    """Minimal batch-embedding interface."""

    @abstractmethod
    async def embed(self, texts: list[str], *, model: str) -> EmbeddingResponse:
        """Return one vector per input, in input order."""


@dataclass
class _RecordedEmbedCall:
    texts: list[str]
    model: str


class FakeEmbeddingClient(EmbeddingClient):
    """In-memory EmbeddingClient for tests.

    Generates deterministic vectors from a hash of the input text — useful
    for similarity tests (same text -> same vector) without needing a real
    model. Vectors are short (8 dims) and deterministic.
    """

    def __init__(
        self,
        *,
        dimensions: int = 8,
        total_tokens: int = 64,
        raises: Exception | None = None,
    ) -> None:
        self._dimensions = dimensions
        self._total_tokens = total_tokens
        self._raises = raises
        self.calls: list[_RecordedEmbedCall] = []

    async def embed(self, texts: list[str], *, model: str) -> EmbeddingResponse:
        self.calls.append(_RecordedEmbedCall(texts=list(texts), model=model))
        if self._raises is not None:
            raise self._raises

        vectors = [self._deterministic_vector(t) for t in texts]
        return EmbeddingResponse(
            embeddings=vectors,
            model=model,
            total_tokens=self._total_tokens,
        )

    def _deterministic_vector(self, text: str) -> list[float]:
        # Hash the text, then unpack the first N bytes as floats in [-1, 1].
        digest = hashlib.sha256(text.encode("utf-8")).digest()
        return [(b - 128) / 128.0 for b in digest[: self._dimensions]]
