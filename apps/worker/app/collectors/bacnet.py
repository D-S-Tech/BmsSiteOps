"""BACnet/IP collector — later sprint deliverable.

Stub implementation. Real implementation will use `bacpypes3` for BACnet/IP
device discovery (WhoIs/IAm) and property reads/subscriptions.
"""

from __future__ import annotations

from collections.abc import AsyncIterator
from typing import TYPE_CHECKING, Any

from app.collectors.base import Collector, CollectorKind

if TYPE_CHECKING:
    from app.collectors.base import CollectorEvent


class BacnetCollector(Collector):
    """Stub collector for BACnet/IP via bacpypes3. Replaced in a later sprint."""

    KIND: CollectorKind = CollectorKind.BACNET

    async def discover(self) -> list[dict[str, Any]]:
        """Broadcast WhoIs and collect IAm responses; enumerate objects."""
        return []

    async def poll(self) -> AsyncIterator[CollectorEvent]:
        """COV subscriptions on configured points + periodic reads."""
        if False:  # pragma: no cover
            yield
