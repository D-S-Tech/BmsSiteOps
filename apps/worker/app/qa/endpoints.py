"""Q&A FastAPI endpoints.

POST /qa/embed   — embed a single piece of text via EmbeddingClient.
POST /qa/answer  — given a question and retrieved contexts, generate the answer.

Both are HMAC-protected via Depends(verify_worker_signature). They're the
inbound twins of the Laravel-side HttpWorkerRagClient (Sprint 7.3).
"""

from __future__ import annotations

from typing import Annotated, Any

from fastapi import APIRouter, Depends
from pydantic import BaseModel, Field

from app.ai.embedding import EmbeddingClient
from app.ai.llm import LLMClient
from app.auth import verify_worker_signature
from app.qa import SYSTEM_PROMPT, build_user_prompt

# ---------------------------------------------------------------------------
# Request / response schemas
# ---------------------------------------------------------------------------


class EmbedRequest(BaseModel):
    text: str = Field(..., min_length=1, max_length=20000)
    model: str | None = None


class EmbedResponse(BaseModel):
    embedding: list[float]
    model: str


class ContextItem(BaseModel):
    content: str
    document_title: str | None = None
    score: float | None = None


class AnswerRequest(BaseModel):
    question: str = Field(..., min_length=1, max_length=10000)
    contexts: list[ContextItem] = Field(default_factory=list)
    model: str | None = None
    max_tokens: int = Field(default=1024, ge=64, le=4096)


class AnswerResponse(BaseModel):
    answer: str
    model: str
    metadata: dict[str, Any]


# ---------------------------------------------------------------------------
# Router factory — keeps the endpoints testable by injecting their deps.
# ---------------------------------------------------------------------------


def make_qa_router(
    embedder: EmbeddingClient,
    llm: LLMClient,
    *,
    default_embedding_model: str = "ollama/nomic-embed-text",
    default_answer_model: str = "claude-sonnet-4-5",
) -> APIRouter:
    """Build a router around the given EmbeddingClient + LLMClient.

    Production wires this in app/main.py with the real LiteLLM-backed
    implementations; tests wire it with Fake* clients so the request /
    response shapes are validated without a live LiteLLM proxy.
    """
    router = APIRouter(prefix="/qa", tags=["qa"])

    @router.post(
        "/embed",
        response_model=EmbedResponse,
        dependencies=[Depends(verify_worker_signature)],
    )
    async def embed_endpoint(payload: Annotated[EmbedRequest, ...]) -> EmbedResponse:
        model = payload.model or default_embedding_model
        result = await embedder.embed([payload.text], model=model)
        # EmbeddingResponse.embeddings is list[list[float]]; we asked for one
        # text so we want the first vector.
        vec = result.embeddings[0] if result.embeddings else []
        return EmbedResponse(embedding=vec, model=result.model or model)

    @router.post(
        "/answer",
        response_model=AnswerResponse,
        dependencies=[Depends(verify_worker_signature)],
    )
    async def answer_endpoint(payload: Annotated[AnswerRequest, ...]) -> AnswerResponse:
        model = payload.model or default_answer_model
        contexts_dicts = [c.model_dump() for c in payload.contexts]
        user_prompt = build_user_prompt(payload.question, contexts_dicts)

        response = await llm.complete(
            system=SYSTEM_PROMPT,
            user=user_prompt,
            model=model,
            max_tokens=payload.max_tokens,
        )

        return AnswerResponse(
            answer=response.text,
            model=response.model or model,
            metadata={
                "input_tokens": response.input_tokens,
                "output_tokens": response.output_tokens,
                "contexts_used": len(payload.contexts),
            },
        )

    return router
