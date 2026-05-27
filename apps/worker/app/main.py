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
from app.config import settings

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
