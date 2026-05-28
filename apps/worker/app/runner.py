"""Sync runner — one collector poll cycle, pushed to the API.

Ties a collector to the ingest client: run `discover()` for devices and
`poll()` for events, assemble a single sync payload, and POST it to the
Laravel internal endpoint. On collector failure, push an error status so the
source's last_status/last_error reflect the problem.

The runner is deliberately decoupled from how sources are loaded. A future
scheduler will query active sources from the database (asyncpg) and hand each
`CollectorConfig` here; for now the runner operates on whatever config it is
given, which keeps it fully unit-testable without a database.
"""

from __future__ import annotations

import structlog

from app.clients.ingest import IngestClient
from app.collectors.bacnet import BacnetCollector
from app.collectors.base import Collector, CollectorConfig, CollectorKind
from app.collectors.niagara import NiagaraCollector
from app.collectors.trmm import TrmmCollector

log = structlog.get_logger(__name__)

# Registry mapping a source kind to its collector implementation.
COLLECTORS: dict[CollectorKind, type[Collector]] = {
    CollectorKind.TRMM: TrmmCollector,
    CollectorKind.NIAGARA: NiagaraCollector,
    CollectorKind.BACNET: BacnetCollector,
}


def build_collector(config: CollectorConfig) -> Collector:
    """Instantiate the collector implementation for a source config."""
    try:
        collector_cls = COLLECTORS[config.kind]
    except KeyError as exc:  # pragma: no cover - guarded by the enum
        raise ValueError(f"No collector registered for kind {config.kind}") from exc
    return collector_cls(config)


class SyncRunner:
    """Runs one collector and pushes the result to the API."""

    def __init__(self, ingest: IngestClient) -> None:
        self._ingest = ingest

    async def run_once(self, config: CollectorConfig) -> dict[str, int]:
        """Run a single poll cycle for one source and push the sync payload.

        Returns the API's response counts. On collector error, pushes an
        error-status sync (no devices/events) and re-raises after reporting.
        """
        collector = build_collector(config)

        try:
            devices = await collector.discover()
            events = [event async for event in collector.poll()]
        except Exception as exc:
            log.warning(
                "collector_failed",
                source_id=config.source_id,
                kind=config.kind.value,
                error=str(exc),
            )
            await self._ingest.sync_source(
                config.source_id,
                {"status": "error", "error": str(exc), "devices": [], "events": []},
            )
            raise

        payload = {
            "status": "ok",
            "devices": devices,
            "events": [self._serialize_event(e) for e in events],
        }

        result = await self._ingest.sync_source(config.source_id, payload)
        log.info(
            "source_synced",
            source_id=config.source_id,
            kind=config.kind.value,
            devices=result.get("devices_synced"),
            events=result.get("events_ingested"),
        )
        return result

    @staticmethod
    def _serialize_event(event: object) -> dict[str, object]:
        """Flatten a CollectorEvent into the JSON shape the API expects."""
        from app.collectors.base import CollectorEvent

        assert isinstance(event, CollectorEvent)
        return {
            "device_external_id": event.device_external_id,
            "metric": event.metric,
            "value": event.value,
            "severity": event.severity,
            "occurred_at": event.timestamp.isoformat(),
            "metadata": event.metadata,
        }
