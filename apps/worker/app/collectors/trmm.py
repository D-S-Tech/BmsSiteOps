"""Tactical RMM collector — Sprint 1 deliverable.

Stub implementation that satisfies the `Collector` interface so the rest of
the worker (scheduler, dependency injection, registration) can be tested
end-to-end before the real TRMM REST integration lands.

Sprint 1 will replace `_unimplemented()` with httpx calls to TRMM's
`/api/v3/agents/` and `/api/v3/alerts/` endpoints.
"""

from __future__ import annotations

from collections.abc import AsyncIterator
from typing import TYPE_CHECKING, Any

from app.collectors.base import Collector, CollectorKind

if TYPE_CHECKING:
    from app.collectors.base import CollectorEvent


class TrmmCollector(Collector):
    """Stub collector for Tactical RMM. Replaced in Sprint 1."""

    KIND: CollectorKind = CollectorKind.TRMM

    async def discover(self) -> list[dict[str, Any]]:
        """Sprint 1: list agents from `/api/v3/agents/` and normalize them."""
        return []

    async def poll(self) -> AsyncIterator[CollectorEvent]:
        """Sprint 1: subscribe to alert webhooks + poll the alerts endpoint."""
        # Empty async generator. `if False: yield` is the idiomatic Python
        # way to declare an async generator that yields nothing.
        if False:  # pragma: no cover
            yield
