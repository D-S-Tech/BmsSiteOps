"""Tridium Niagara collector — Sprint 2 deliverable.

Stub implementation. Sprint 2 will replace this with a real Fox protocol
client (custom asyncio implementation; bacpypes3 doesn't cover Fox).
"""

from __future__ import annotations

from collections.abc import AsyncIterator
from typing import TYPE_CHECKING, Any

from app.collectors.base import Collector, CollectorKind

if TYPE_CHECKING:
    from app.collectors.base import CollectorEvent


class NiagaraCollector(Collector):
    """Stub collector for Tridium Niagara via Fox protocol. Replaced in Sprint 2."""

    KIND: CollectorKind = CollectorKind.NIAGARA

    async def discover(self) -> list[dict[str, Any]]:
        """Sprint 2: walk the Niagara component tree via Fox and list points."""
        return []

    async def poll(self) -> AsyncIterator[CollectorEvent]:
        """Sprint 2: subscribe to Fox value-changed events on configured points."""
        if False:  # pragma: no cover
            yield
