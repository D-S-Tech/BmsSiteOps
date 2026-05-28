"""Tests for the Niagara collector (oBIX transport) using respx-mocked HTTP."""

from __future__ import annotations

import httpx
import pytest
import respx

from app.collectors.base import CollectorConfig, CollectorKind
from app.collectors.niagara import NiagaraCollector

BASE = "https://jace.example.com"

CONTAINER = """<?xml version="1.0"?>
<obj href="/obix/config/" displayName="Station Config">
  <obj name="ZoneTemp" href="points/ZoneTemp/" displayName="Zone">
    <real name="out" href="points/ZoneTemp/out/" val="72.5"
          displayName="Zone Temperature" status="ok" unit="obix:units/fahrenheit"/>
  </obj>
  <bool name="FanStatus" href="points/FanStatus/" val="true"
        displayName="Fan Status" status="ok"/>
  <real name="Pressure" href="points/Pressure/" val="3.2"
        displayName="Duct Pressure" status="alarm" unit="obix:units/inch_water"/>
  <real name="DeadSensor" href="points/DeadSensor/" val="0.0"
        displayName="Dead Sensor" status="down"/>
</obj>
"""


def _config(transport: str | None = "obix", metadata: dict | None = None) -> CollectorConfig:
    return CollectorConfig(
        source_id=11,
        tenant_id=2,
        site_id=4,
        kind=CollectorKind.NIAGARA,
        name="JACE-8000",
        transport=transport,
        base_url=BASE,
        credentials={"username": "admin", "password": "secret"},
        poll_interval_seconds=60,
        metadata=metadata or {},
    )


@respx.mock
async def test_discover_maps_points_to_devices() -> None:
    respx.get(f"{BASE}/obix/config/").mock(return_value=httpx.Response(200, text=CONTAINER))

    devices = await NiagaraCollector(_config()).discover()

    # 4 value-bearing points (ZoneTemp/out, FanStatus, Pressure, DeadSensor)
    assert len(devices) == 4

    by_id = {d["external_id"]: d for d in devices}
    temp = by_id["points/ZoneTemp/out/"]
    assert temp["name"] == "Zone Temperature"
    assert temp["type"] == "point"
    assert temp["status"] == "online"
    assert temp["metadata"]["unit"] == "fahrenheit"

    # 'down' status normalizes to offline
    assert by_id["points/DeadSensor/"]["status"] == "offline"
    # 'alarm' status is still online (alarm != down)
    assert by_id["points/Pressure/"]["status"] == "online"


@respx.mock
async def test_poll_maps_points_to_events_with_severity() -> None:
    respx.get(f"{BASE}/obix/config/").mock(return_value=httpx.Response(200, text=CONTAINER))

    events = [e async for e in NiagaraCollector(_config()).poll()]

    assert len(events) == 4
    by_dev = {e.device_external_id: e for e in events}

    # ok status -> no severity
    assert by_dev["points/ZoneTemp/out/"].severity is None
    assert by_dev["points/ZoneTemp/out/"].value == "72.5"
    assert by_dev["points/ZoneTemp/out/"].kind == CollectorKind.NIAGARA
    assert by_dev["points/ZoneTemp/out/"].metric == "value"

    # alarm -> critical
    assert by_dev["points/Pressure/"].severity == "critical"


@respx.mock
async def test_custom_points_href_is_used() -> None:
    route = respx.get(f"{BASE}/obix/config/Drivers/").mock(
        return_value=httpx.Response(200, text="<obj/>")
    )

    cfg = _config(metadata={"obix_points_href": "/obix/config/Drivers/"})
    await NiagaraCollector(cfg).discover()

    assert route.called


async def test_rest_transport_raises_not_implemented_on_discover() -> None:
    with pytest.raises(NotImplementedError):
        await NiagaraCollector(_config(transport="rest")).discover()


async def test_rest_transport_raises_not_implemented_on_poll() -> None:
    with pytest.raises(NotImplementedError):
        _ = [e async for e in NiagaraCollector(_config(transport="rest")).poll()]


# --- Fox transport (mapping via fake; live session is experimental) ----------

from app.clients.fox import FoxPoint, FoxTransport  # noqa: E402


class FakeFoxTransport(FoxTransport):
    """In-memory FoxTransport returning canned points."""

    def __init__(self, points: list[FoxPoint]) -> None:
        self._points = points
        self.closed = False

    async def read_points(self) -> list[FoxPoint]:
        return self._points

    async def close(self) -> None:
        self.closed = True


def _fox_points() -> list[FoxPoint]:
    return [
        FoxPoint(
            handle="slot:/Drivers/Temp", name="Zone Temp", value=72.5, status="ok", units="°F"
        ),
        FoxPoint(handle="slot:/Drivers/Press", name="Duct Press", value=3.2, status="alarm"),
        FoxPoint(handle="slot:/Drivers/Dead", name="Dead Pt", value=0.0, status="down"),
    ]


async def test_fox_discover_maps_points_to_devices() -> None:
    collector = NiagaraCollector(
        _config(transport="fox"), fox_transport=FakeFoxTransport(_fox_points())
    )

    devices = await collector.discover()

    assert len(devices) == 3
    by_id = {d["external_id"]: d for d in devices}
    assert by_id["slot:/Drivers/Temp"]["name"] == "Zone Temp"
    assert by_id["slot:/Drivers/Temp"]["type"] == "point"
    assert by_id["slot:/Drivers/Temp"]["status"] == "online"
    # 'down' status -> offline
    assert by_id["slot:/Drivers/Dead"]["status"] == "offline"
    # 'alarm' is still online (alarm != down)
    assert by_id["slot:/Drivers/Press"]["status"] == "online"


async def test_fox_poll_maps_points_to_events_with_severity() -> None:
    collector = NiagaraCollector(
        _config(transport="fox"), fox_transport=FakeFoxTransport(_fox_points())
    )

    events = [e async for e in collector.poll()]

    assert len(events) == 3
    by_dev = {e.device_external_id: e for e in events}
    assert by_dev["slot:/Drivers/Temp"].severity is None
    assert by_dev["slot:/Drivers/Temp"].value == 72.5
    assert by_dev["slot:/Drivers/Temp"].kind == CollectorKind.NIAGARA
    # alarm -> critical
    assert by_dev["slot:/Drivers/Press"].severity == "critical"


async def test_fox_without_transport_raises_experimental() -> None:
    # No injected transport -> builds the real FoxClient, whose live session
    # is experimental and raises NotImplementedError.
    with pytest.raises(NotImplementedError):
        await NiagaraCollector(_config(transport="fox")).discover()
