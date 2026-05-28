"""Tridium Niagara collector.

Reaches a Niagara station via a configurable transport (see the `transport`
field on the source): oBIX is implemented here; REST and Fox are reserved for
later sub-sprints and raise NotImplementedError until then.

oBIX mapping
------------
Each value-bearing oBIX point under the configured container becomes a device;
its current value becomes a "value" event. oBIX status flags drive both device
status and event severity:

    status down/disabled        -> device offline
    status alarm/unackedAlarm   -> event severity critical
    status fault/alert/unacked  -> event severity warning
    otherwise                   -> device online, event severity none

The points container href defaults to "/obix/config/" and can be overridden
per source via metadata["obix_points_href"].
"""

from __future__ import annotations

from collections.abc import AsyncIterator
from datetime import UTC, datetime
from typing import Any

from app.clients.obix import (
    ALARM_STATUSES,
    ALERT_STATUSES,
    DOWN_STATUSES,
    ObixClient,
    ObixObject,
)
from app.collectors.base import Collector, CollectorEvent, CollectorKind

DEFAULT_OBIX_POINTS_HREF = "/obix/config/"


def _device_status(obix_status: str) -> str:
    return "offline" if obix_status in DOWN_STATUSES else "online"


def _event_severity(obix_status: str) -> str | None:
    if obix_status in ALARM_STATUSES:
        return "critical"
    if obix_status in ALERT_STATUSES:
        return "warning"
    return None


def _collect_points(obj: ObixObject) -> list[ObixObject]:
    """Recursively collect all value-bearing points in an oBIX tree."""
    points: list[ObixObject] = []
    if obj.is_point and obj.href:
        points.append(obj)
    for child in obj.children:
        points.extend(_collect_points(child))
    return points


class NiagaraCollector(Collector):
    """Collects devices and events from a Niagara station."""

    KIND: CollectorKind = CollectorKind.NIAGARA

    def _points_href(self) -> str:
        href = self.config.metadata.get("obix_points_href")
        return href if isinstance(href, str) and href else DEFAULT_OBIX_POINTS_HREF

    def _obix_client(self) -> ObixClient:
        creds = self.config.credentials
        return ObixClient(
            base_url=self.config.base_url or "",
            username=creds.get("username", ""),
            password=creds.get("password", ""),
        )

    async def _obix_points(self) -> list[ObixObject]:
        container = await self._obix_client().read(self._points_href())
        return _collect_points(container)

    async def discover(self) -> list[dict[str, Any]]:
        """Enumerate oBIX points as devices."""
        if self.config.transport != "obix":
            raise NotImplementedError(
                f"Niagara transport {self.config.transport!r} is not implemented yet "
                "(only 'obix' is available in this sub-sprint)."
            )

        devices: list[dict[str, Any]] = []
        for point in await self._obix_points():
            devices.append(
                {
                    "external_id": point.href,
                    "name": point.display(),
                    "type": "point",
                    "status": _device_status(point.status),
                    "last_seen_at": None,
                    "metadata": {
                        "obix_tag": point.tag,
                        "unit": point.unit,
                        "obix_status": point.status,
                    },
                }
            )
        return devices

    async def poll(self) -> AsyncIterator[CollectorEvent]:
        """Read current point values and yield one event each."""
        if self.config.transport != "obix":
            raise NotImplementedError(
                f"Niagara transport {self.config.transport!r} is not implemented yet "
                "(only 'obix' is available in this sub-sprint)."
            )

        now = datetime.now(UTC)
        for point in await self._obix_points():
            yield CollectorEvent(
                source_id=self.config.source_id,
                tenant_id=self.config.tenant_id,
                site_id=self.config.site_id,
                kind=CollectorKind.NIAGARA,
                device_external_id=point.href or point.display(),
                timestamp=now,
                metric="value",
                value=point.val,
                severity=_event_severity(point.status),
                metadata={"unit": point.unit, "obix_status": point.status},
            )
