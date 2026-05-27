"""Collector abstract base class.

A Collector ingests operational telemetry from one external source and
normalizes it into `CollectorEvent` instances pushed into Redis pub/sub for
the Laravel side. Every collector kind (TRMM, Niagara, BACnet, ...) is a
subclass of `Collector`.

Lifecycle:

    config = CollectorConfig(...)            # from the sources table in DB
    collector = TrmmCollector(config)
    devices = await collector.discover()     # one-shot device enumeration
    async for event in collector.poll():     # iterative polling
        await publish(event)

Design constraints:

- Every operation is async (FastAPI + asyncpg world).
- Collectors must be tenant-aware. The `CollectorConfig` carries `tenant_id`
  and `site_id`; every emitted event carries them along.
- Collectors must not raise on transient network errors. They yield no events
  for that poll cycle and log; the scheduler decides when to retry.
- Collectors must not contact the network at construction time. All I/O
  belongs in `discover()` / `poll()`.
"""

from __future__ import annotations

from abc import ABC, abstractmethod
from collections.abc import AsyncIterator
from datetime import datetime
from enum import StrEnum
from typing import Any

from pydantic import BaseModel, ConfigDict, Field


class CollectorKind(StrEnum):
    """Enumeration of all supported source kinds.

    Matches the `kind` column on the `sources` table (added in Sprint 1).
    Adding a new collector means: add the enum value here, add the migration
    in Laravel, and implement the subclass under `app/collectors/`.
    """

    TRMM = "trmm"
    NIAGARA = "niagara"
    BACNET = "bacnet"


class CollectorConfig(BaseModel):
    """Configuration for a single collector instance.

    Hydrated from the `sources` table row keyed by `id`. Per-source secrets
    (API tokens, passwords) live in the database — never in environment
    variables, never in code.
    """

    model_config = ConfigDict(frozen=True, extra="forbid")

    source_id: int
    tenant_id: int
    site_id: int
    kind: CollectorKind
    name: str
    base_url: str | None = None
    credentials: dict[str, str] = Field(default_factory=dict)
    poll_interval_seconds: int = 60


class CollectorEvent(BaseModel):
    """Normalized event emitted by a collector.

    All collectors emit this shape regardless of upstream protocol. The
    Laravel side persists events into the `events` table and triggers
    downstream behavior (alert triage, AI handlers, notifications).
    """

    model_config = ConfigDict(extra="forbid")

    source_id: int
    tenant_id: int
    site_id: int
    kind: CollectorKind
    device_external_id: str
    timestamp: datetime
    metric: str
    value: float | str | bool | None
    metadata: dict[str, Any] = Field(default_factory=dict)


class Collector(ABC):
    """Abstract base class for all data-source collectors."""

    def __init__(self, config: CollectorConfig) -> None:
        self.config = config

    @property
    def kind(self) -> CollectorKind:
        """Source kind this collector implements. Must match the subclass."""
        return self.config.kind

    @abstractmethod
    async def discover(self) -> list[dict[str, Any]]:
        """Enumerate devices/points exposed by the source.

        Called once on collector start and on demand (e.g., when the operator
        clicks "Re-discover" in the UI). Returns a list of normalized device
        descriptors; the Laravel side reconciles them against the `devices`
        table for this source.
        """

    @abstractmethod
    def poll(self) -> AsyncIterator[CollectorEvent]:
        """Yield events as they become available.

        Implementations decide whether to issue periodic polls, subscribe to
        a push stream, or both. The scheduler calls `poll()` once per
        collector and keeps the iterator alive for the lifetime of the
        collector — yielding events as they happen.

        On transient errors, implementations log and continue. On fatal
        errors (e.g., authentication broken permanently), raise; the
        scheduler will mark the source as failed and stop calling poll.
        """
