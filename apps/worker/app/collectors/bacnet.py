"""BACnet/IP collector.

Discovers BACnet devices (Who-Is) and reads their value-bearing objects'
present-values, mapping them into the platform's device/event shapes.

Mapping
-------
Unlike Niagara/oBIX (where each point is a device), a BACnet *device* (a
physical controller) maps to a platform device, and its objects map to events:

    BACnet device          -> device (type 'bacnet-device')
    object present-value    -> 'value' event (metric = object identifier)
    statusFlags inAlarm     -> event severity critical
    statusFlags fault       -> event severity warning
    statusFlags outOfService-> device status offline

The collector depends only on the BacnetTransport interface; the real
bacpypes3 transport is built lazily so unit tests inject a fake.
"""

from __future__ import annotations

from collections.abc import AsyncIterator
from datetime import UTC, datetime
from typing import Any

from app.clients.bacnet import BacnetDevice, BacnetPoint, BacnetTransport
from app.collectors.base import Collector, CollectorConfig, CollectorEvent, CollectorKind

DEFAULT_LOCAL_ADDRESS = "0.0.0.0/24:47808"


class BacnetCollector(Collector):
    """Collects devices and events from a BACnet/IP internetwork."""

    KIND: CollectorKind = CollectorKind.BACNET

    def __init__(
        self,
        config: CollectorConfig,
        transport: BacnetTransport | None = None,
    ) -> None:
        super().__init__(config)
        self._transport = transport

    def _get_transport(self) -> BacnetTransport:
        """Return the injected transport, or lazily build the real bacpypes3 one."""
        if self._transport is not None:
            return self._transport

        # Imported here so the bacpypes3 dependency stays out of the unit-test
        # path and is only loaded when actually talking to hardware.
        from app.clients.bacnet_bacpypes import Bacpypes3Transport

        meta = self.config.metadata
        self._transport = Bacpypes3Transport(
            local_address=str(meta.get("local_address", DEFAULT_LOCAL_ADDRESS)),
            device_id=int(meta.get("local_device_id", 599)),
            who_is_low=meta.get("device_low"),
            who_is_high=meta.get("device_high"),
        )
        return self._transport

    @staticmethod
    def _device_status(device_offline: bool) -> str:
        return "offline" if device_offline else "online"

    @staticmethod
    def _event_severity(point: BacnetPoint) -> str | None:
        if point.in_alarm:
            return "critical"
        if point.fault:
            return "warning"
        return None

    def _device_dict(self, device: BacnetDevice) -> dict[str, Any]:
        return {
            "external_id": str(device.device_id),
            "name": device.name or f"BACnet device {device.device_id}",
            "type": "bacnet-device",
            "status": "online",
            "last_seen_at": None,
            "metadata": {
                "address": device.address,
                "vendor_id": device.vendor_id,
                "model_name": device.model_name,
            },
        }

    async def discover(self) -> list[dict[str, Any]]:
        """Who-Is broadcast → one device per responding BACnet controller."""
        transport = self._get_transport()
        devices = await transport.who_is()
        return [self._device_dict(d) for d in devices]

    async def poll(self) -> AsyncIterator[CollectorEvent]:
        """Read each device's value-bearing objects → one event per object."""
        transport = self._get_transport()
        now = datetime.now(UTC)

        for device in await transport.who_is():
            for point in await transport.read_points(device):
                yield CollectorEvent(
                    source_id=self.config.source_id,
                    tenant_id=self.config.tenant_id,
                    site_id=self.config.site_id,
                    kind=CollectorKind.BACNET,
                    device_external_id=str(device.device_id),
                    timestamp=now,
                    metric=point.identifier,
                    value=point.present_value,
                    severity=self._event_severity(point),
                    metadata={
                        "object_name": point.name,
                        "units": point.units,
                        "out_of_service": point.out_of_service,
                    },
                )
