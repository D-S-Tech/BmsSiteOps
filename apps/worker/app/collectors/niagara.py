"""Tridium Niagara collector.

Reaches a Niagara station via a configurable transport (the `transport` field
on the source):

  * obix — oBIX over HTTP/XML. Primary, fully implemented + tested.
  * fox  — native Fox protocol. Mapping implemented + tested via a fake
           transport; the live Fox session is experimental (see
           app/clients/fox_client.py) and raises until JACE-validated.
  * rest — Niagara 4 REST API. Reserved; raises NotImplementedError.

Mapping (same for both oBIX and Fox): each value-bearing point becomes a
device, and its current value becomes a "value" event. Niagara status drives
device status (down/disabled -> offline) and event severity (alarm ->
critical, fault/stale/alert -> warning).

The oBIX points container defaults to "/obix/config/" (override via
metadata["obix_points_href"]). For Fox, a FoxTransport may be injected for
testing; otherwise the real (experimental) FoxClient is built lazily.
"""

from __future__ import annotations

from collections.abc import AsyncIterator
from datetime import UTC, datetime
from typing import Any

from app.clients.fox import (
    FoxPoint,
    FoxTransport,
    fox_device_status,
    fox_event_severity,
)
from app.clients.obix import (
    ALARM_STATUSES,
    ALERT_STATUSES,
    DOWN_STATUSES,
    ObixClient,
    ObixObject,
)
from app.collectors.base import (
    Collector,
    CollectorConfig,
    CollectorEvent,
    CollectorKind,
)

DEFAULT_OBIX_POINTS_HREF = "/obix/config/"


def _obix_device_status(obix_status: str) -> str:
    return "offline" if obix_status in DOWN_STATUSES else "online"


def _obix_event_severity(obix_status: str) -> str | None:
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

    def __init__(
        self,
        config: CollectorConfig,
        fox_transport: FoxTransport | None = None,
    ) -> None:
        super().__init__(config)
        self._fox_transport = fox_transport

    # --- dispatch -------------------------------------------------------------

    async def discover(self) -> list[dict[str, Any]]:
        match self.config.transport:
            case "obix":
                return await self._discover_obix()
            case "fox":
                return await self._discover_fox()
            case other:
                raise NotImplementedError(
                    f"Niagara transport {other!r} is not implemented (use 'obix' or 'fox')."
                )

    async def poll(self) -> AsyncIterator[CollectorEvent]:
        match self.config.transport:
            case "obix":
                async for event in self._poll_obix():
                    yield event
            case "fox":
                async for event in self._poll_fox():
                    yield event
            case other:
                raise NotImplementedError(
                    f"Niagara transport {other!r} is not implemented (use 'obix' or 'fox')."
                )

    # --- oBIX transport -------------------------------------------------------

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

    async def _discover_obix(self) -> list[dict[str, Any]]:
        devices: list[dict[str, Any]] = []
        for point in await self._obix_points():
            devices.append(
                {
                    "external_id": point.href,
                    "name": point.display(),
                    "type": "point",
                    "status": _obix_device_status(point.status),
                    "last_seen_at": None,
                    "metadata": {
                        "obix_tag": point.tag,
                        "unit": point.unit,
                        "obix_status": point.status,
                    },
                }
            )
        return devices

    async def _poll_obix(self) -> AsyncIterator[CollectorEvent]:
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
                severity=_obix_event_severity(point.status),
                metadata={"unit": point.unit, "obix_status": point.status},
            )

    # --- Fox transport --------------------------------------------------------

    def _fox(self) -> FoxTransport:
        """Return the injected Fox transport, or lazily build the real client."""
        if self._fox_transport is not None:
            return self._fox_transport

        # Imported here so the experimental Fox client stays off the default
        # import path until a Fox source is actually polled.
        from app.clients.fox_client import FoxClient

        host = (self.config.base_url or "").removeprefix("fox://").removeprefix("foxs://")
        port = FoxClient.DEFAULT_PORT
        if ":" in host:
            host, _, port_str = host.partition(":")
            port = int(port_str) if port_str.isdigit() else port

        creds = self.config.credentials
        self._fox_transport = FoxClient(
            host=host,
            username=creds.get("username", ""),
            password=creds.get("password", ""),
            port=port,
        )
        return self._fox_transport

    @staticmethod
    def _fox_device_dict(point: FoxPoint) -> dict[str, Any]:
        return {
            "external_id": point.handle,
            "name": point.name,
            "type": "point",
            "status": fox_device_status(point.status),
            "last_seen_at": None,
            "metadata": {"unit": point.units, "fox_status": point.status},
        }

    async def _discover_fox(self) -> list[dict[str, Any]]:
        points = await self._fox().read_points()
        return [self._fox_device_dict(p) for p in points]

    async def _poll_fox(self) -> AsyncIterator[CollectorEvent]:
        now = datetime.now(UTC)
        for point in await self._fox().read_points():
            yield CollectorEvent(
                source_id=self.config.source_id,
                tenant_id=self.config.tenant_id,
                site_id=self.config.site_id,
                kind=CollectorKind.NIAGARA,
                device_external_id=point.handle,
                timestamp=now,
                metric="value",
                value=point.value,
                severity=fox_event_severity(point.status),
                metadata={"unit": point.units, "fox_status": point.status},
            )
