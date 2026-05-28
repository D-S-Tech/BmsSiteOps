"""Tests for the BACnet collector using a fake transport.

The fake exercises the collector's device/event mapping — the logic this
module owns. Protocol correctness belongs to bacpypes3 and is out of scope
here (the real transport is integration-validated on hardware).
"""

from __future__ import annotations

from app.clients.bacnet import BacnetDevice, BacnetPoint, BacnetTransport
from app.collectors.bacnet import BacnetCollector
from app.collectors.base import CollectorConfig, CollectorKind


class FakeBacnetTransport(BacnetTransport):
    """In-memory BacnetTransport returning canned devices and points."""

    def __init__(
        self,
        devices: list[BacnetDevice],
        points: dict[int, list[BacnetPoint]] | None = None,
    ) -> None:
        self._devices = devices
        self._points = points or {}
        self.closed = False

    async def who_is(self) -> list[BacnetDevice]:
        return self._devices

    async def read_points(self, device: BacnetDevice) -> list[BacnetPoint]:
        return self._points.get(device.device_id, [])

    async def close(self) -> None:
        self.closed = True


def _config() -> CollectorConfig:
    return CollectorConfig(
        source_id=21,
        tenant_id=3,
        site_id=6,
        kind=CollectorKind.BACNET,
        name="BACnet site",
        poll_interval_seconds=60,
    )


def _devices() -> list[BacnetDevice]:
    return [
        BacnetDevice(device_id=1001, address="10.0.0.5", name="VAV-101", vendor_id=5),
        BacnetDevice(device_id=1002, address="10.0.0.6", name=None, vendor_id=5),
    ]


async def test_discover_maps_devices() -> None:
    collector = BacnetCollector(_config(), transport=FakeBacnetTransport(_devices()))

    devices = await collector.discover()

    assert len(devices) == 2
    by_id = {d["external_id"]: d for d in devices}

    assert by_id["1001"]["name"] == "VAV-101"
    assert by_id["1001"]["type"] == "bacnet-device"
    assert by_id["1001"]["status"] == "online"
    assert by_id["1001"]["metadata"]["address"] == "10.0.0.5"

    # Device without a name falls back to a generated label.
    assert by_id["1002"]["name"] == "BACnet device 1002"


async def test_poll_maps_points_to_events() -> None:
    points = {
        1001: [
            BacnetPoint("analogInput", 1, present_value=72.5, name="ZoneTemp"),
            BacnetPoint("binaryValue", 2, present_value=True, name="FanCmd", in_alarm=True),
            BacnetPoint("multiStateValue", 3, present_value=2, name="Mode", fault=True),
        ]
    }
    transport = FakeBacnetTransport(_devices(), points)
    collector = BacnetCollector(_config(), transport=transport)

    events = [e async for e in collector.poll()]

    # Only device 1001 has points; 1002 has none.
    assert len(events) == 3
    by_metric = {e.metric: e for e in events}

    temp = by_metric["analogInput:1"]
    assert temp.device_external_id == "1001"
    assert temp.kind == CollectorKind.BACNET
    assert temp.value == 72.5
    assert temp.severity is None
    assert temp.metadata["object_name"] == "ZoneTemp"

    # inAlarm -> critical; value carries through as bool
    fan = by_metric["binaryValue:2"]
    assert fan.severity == "critical"
    assert fan.value is True

    # fault -> warning; multistate int value preserved
    mode = by_metric["multiStateValue:3"]
    assert mode.severity == "warning"
    assert mode.value == 2


async def test_poll_with_no_devices_yields_nothing() -> None:
    collector = BacnetCollector(_config(), transport=FakeBacnetTransport([]))
    events = [e async for e in collector.poll()]
    assert events == []


def test_point_identifier_format() -> None:
    point = BacnetPoint("analogInput", 7, present_value=1.0)
    assert point.identifier == "analogInput:7"
