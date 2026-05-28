"""FastAPI application entrypoint.

Run in development:

    cd apps/worker
    uv run uvicorn app.main:app --reload --host 0.0.0.0 --port 8000

In production (inside the worker container):

    uvicorn app.main:app --host 0.0.0.0 --port 8000 --workers 2
"""

from __future__ import annotations

from contextlib import asynccontextmanager
from typing import TYPE_CHECKING

from fastapi import FastAPI

from app import __version__
from app.ai.litellm_client import LiteLLMClient
from app.ai.litellm_embedding_client import LiteLlmEmbeddingClient
from app.config import settings
from app.mcp.laravel_client import LaravelClient
from app.qa.endpoints import make_qa_router

if TYPE_CHECKING:
    from collections.abc import AsyncIterator


@asynccontextmanager
async def lifespan(_app: FastAPI) -> AsyncIterator[None]:
    """Application lifespan — startup + shutdown hooks.

    Sprint 0 keeps this minimal. Future sprints add:
      - asyncpg connection pool
      - Redis client
      - Collector scheduler
      - LiteLLM proxy client
    """
    # Touch settings() so misconfiguration fails fast at startup.
    settings()
    yield


app = FastAPI(
    title="BmsSiteOps Worker",
    version=__version__,
    description=(
        "Async worker hosting data-source collectors (TRMM, Niagara, BACnet) "
        "and AI task handlers for the BmsSiteOps platform."
    ),
    lifespan=lifespan,
)


@app.get("/health", tags=["meta"])
def health() -> dict[str, str]:
    """Liveness probe. No auth, no side effects."""
    return {"status": "ok", "version": __version__}


# --- Q&A endpoints (Sprint 7.4) ---------------------------------------------
#
# Mount /qa/embed + /qa/answer. Both are HMAC-protected (Laravel signs every
# request). The clients are constructed lazily at module import time so a
# misconfigured LiteLLM proxy URL doesn't break /health.

_cfg = settings()
_qa_embedder = LiteLlmEmbeddingClient(
    proxy_url=str(_cfg.litellm_base_url),
    api_key=_cfg.litellm_master_key.get_secret_value(),
)
_qa_llm = LiteLLMClient(
    base_url=str(_cfg.litellm_base_url),
    master_key=_cfg.litellm_master_key.get_secret_value(),
)
app.include_router(make_qa_router(_qa_embedder, _qa_llm))


# --- MCP server (Sprint 7.4) ------------------------------------------------
#
# Optional — wired only when MCP_API_TOKEN is configured. The token is a
# Sanctum personal access token the MCP server uses to call the Laravel
# public API on behalf of the operator. Live SSE handshake against a real
# MCP client is integration-only and flagged 'needs validation'.

if _cfg.mcp_api_token.get_secret_value():
    from app.mcp.server import mount_on_fastapi

    _laravel = LaravelClient(
        base_url=_cfg.laravel_api_url,
        api_token=_cfg.mcp_api_token.get_secret_value(),
    )
    mount_on_fastapi(app, _laravel)
