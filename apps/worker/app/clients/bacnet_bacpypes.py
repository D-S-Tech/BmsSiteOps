"""Real BACnet/IP transport backed by bacpypes3.

Isolated from the collector and imported lazily so the heavy bacpypes3
dependency never enters the unit-test path. bacpypes3 is a mature, standards-
correct BACnet stack — protocol correctness is delegated to it; this module
only adapts its API to the `BacnetTransport` interface.

NOTE — hardware validation: the bacpypes3 wiring below (application setup,
property identifiers, status-flag decoding) is written against the documented
bacpypes3 API but has not been exercised against a live BACnet/IP internetwork
in CI. Validate on real hardware before trusting in production. The mapping
the collector performs on top of this is unit-tested via a fake transport.
"""

from __future__ import annotations

from typing import Any

import structlog

from app.clients.bacnet import (
    VALUE_OBJECT_TYPES,
    BacnetDevice,
    BacnetPoint,
    BacnetTransport,
)

log = structlog.get_logger(__name__)


class Bacpypes3Transport(BacnetTransport):
    """BACnet/IP transport using a bacpypes3 Application."""

    def __init__(
        self,
        local_address: str,
        device_id: int = 599,
        device_name: str = "BmsSiteOps",
        *,
        who_is_low: int | None = None,
        who_is_high: int | None = None,
        max_points_per_device: int = 200,
    ) -> None:
        self._local_address = local_address
        self._device_id = device_id
        self._device_name = device_name
        self._who_is_low = who_is_low
        self._who_is_high = who_is_high
        self._max_points = max_points_per_device
        self._app: Any = None

    def _application(self) -> Any:
        """Lazily build the bacpypes3 Application (imports bacpypes3 here)."""
        if self._app is not None:
            return self._app

        from bacpypes3.app import Application
        from bacpypes3.local.device import DeviceObject
        from bacpypes3.local.networkport import NetworkPortObject

        device_object = DeviceObject(
            objectName=self._device_name,
            objectIdentifier=("device", self._device_id),
            vendorIdentifier=999,
        )
        network_port = NetworkPortObject(self._local_address)
        self._app = Application.from_object_list([device_object, network_port])
        return self._app

    async def who_is(self) -> list[BacnetDevice]:
        app = self._application()
        i_ams = await app.who_is(self._who_is_low, self._who_is_high)

        devices: list[BacnetDevice] = []
        for i_am in i_ams:
            device_id = i_am.iAmDeviceIdentifier[1]
            address = str(i_am.pduSource)
            name = await self._safe_read(app, address, ("device", device_id), "objectName")
            devices.append(
                BacnetDevice(
                    device_id=device_id,
                    address=address,
                    name=name,
                    vendor_id=getattr(i_am, "vendorID", None),
                )
            )
        return devices

    async def read_points(self, device: BacnetDevice) -> list[BacnetPoint]:
        app = self._application()
        object_list = await self._safe_read(
            app, device.address, ("device", device.device_id), "objectList"
        )
        if not object_list:
            return []

        points: list[BacnetPoint] = []
        for obj_id in object_list[: self._max_points]:
            object_type, instance = obj_id[0], obj_id[1]
            if str(object_type) not in VALUE_OBJECT_TYPES:
                continue

            present_value = await self._safe_read(app, device.address, obj_id, "presentValue")
            if present_value is None:
                continue

            name = await self._safe_read(app, device.address, obj_id, "objectName")
            status_flags = await self._safe_read(app, device.address, obj_id, "statusFlags")
            in_alarm, fault, _overridden, out_of_service = self._decode_flags(status_flags)

            points.append(
                BacnetPoint(
                    object_type=str(object_type),
                    instance=int(instance),
                    present_value=present_value,
                    name=name,
                    in_alarm=in_alarm,
                    fault=fault,
                    out_of_service=out_of_service,
                )
            )
        return points

    async def close(self) -> None:
        if self._app is not None:
            self._app.close()
            self._app = None

    @staticmethod
    async def _safe_read(app: Any, address: str, obj_id: Any, prop: str) -> Any:
        """Read a property, returning None on any error (collectors must not raise)."""
        try:
            return await app.read_property(address, obj_id, prop)
        except Exception as exc:
            log.debug("bacnet_read_failed", address=address, prop=prop, error=str(exc))
            return None

    @staticmethod
    def _decode_flags(status_flags: Any) -> tuple[bool, bool, bool, bool]:
        """Decode a BACnet StatusFlags bitstring [inAlarm, fault, overridden, oos]."""
        if not status_flags:
            return (False, False, False, False)
        try:
            bits = list(status_flags)
            return (bool(bits[0]), bool(bits[1]), bool(bits[2]), bool(bits[3]))
        except (TypeError, IndexError):
            return (False, False, False, False)
