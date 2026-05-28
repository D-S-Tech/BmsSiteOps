"""Tests for the oBIX client and XML parser."""

from __future__ import annotations

import httpx
import pytest
import respx

from app.clients.obix import ObixClient, parse_obix

BASE = "https://jace.example.com"

SIMPLE_POINT = """<?xml version="1.0"?>
<real val="72.5" href="points/ZoneTemp/out/" displayName="Zone Temperature"
      status="ok" unit="obix:units/fahrenheit"/>
"""

NAMESPACED = """<?xml version="1.0"?>
<obj xmlns="http://obix.org/ns/schema/1.0" href="/obix/">
  <real val="1.5" href="x/" status="ok"/>
</obj>
"""

CONTAINER = """<?xml version="1.0"?>
<obj href="/obix/config/" displayName="Station Config">
  <obj name="ZoneTemp" href="points/ZoneTemp/" displayName="Zone">
    <real name="out" href="points/ZoneTemp/out/" val="72.5"
          displayName="Zone Temperature" status="ok" unit="obix:units/fahrenheit"/>
  </obj>
  <bool name="FanStatus" href="points/FanStatus/" val="true"
        displayName="Fan Status" status="ok"/>
  <real name="Pressure" href="points/Pressure/" val="0.0"
        displayName="Duct Pressure" status="alarm" unit="obix:units/inch_water"/>
</obj>
"""


def test_parse_simple_point() -> None:
    obj = parse_obix(SIMPLE_POINT)
    assert obj.tag == "real"
    assert obj.val == "72.5"
    assert obj.status == "ok"
    assert obj.display_name == "Zone Temperature"
    # unit leaf is extracted from "obix:units/fahrenheit"
    assert obj.unit == "fahrenheit"
    assert obj.is_point is True


def test_parse_strips_namespace() -> None:
    obj = parse_obix(NAMESPACED)
    assert obj.tag == "obj"  # not "{http://...}obj"
    assert obj.is_point is False
    assert len(obj.children) == 1
    assert obj.children[0].tag == "real"
    assert obj.children[0].is_point is True


def test_container_children_parsed() -> None:
    obj = parse_obix(CONTAINER)
    assert obj.tag == "obj"
    assert len(obj.children) == 3
    names = {c.name for c in obj.children}
    assert names == {"ZoneTemp", "FanStatus", "Pressure"}


@respx.mock
async def test_client_read_parses_response() -> None:
    respx.get(f"{BASE}/obix/config/").mock(return_value=httpx.Response(200, text=CONTAINER))

    obj = await ObixClient(BASE, "admin", "secret").read("/obix/config/")
    assert obj.tag == "obj"
    assert len(obj.children) == 3


@respx.mock
async def test_client_sends_basic_auth() -> None:
    route = respx.get(f"{BASE}/obix/about/").mock(return_value=httpx.Response(200, text="<obj/>"))

    await ObixClient(BASE, "admin", "secret").read("/obix/about/")

    assert route.called
    # httpx encodes basic auth into the Authorization header
    assert route.calls.last.request.headers["Authorization"].startswith("Basic ")


@respx.mock
async def test_client_raises_on_http_error() -> None:
    respx.get(f"{BASE}/obix/config/").mock(return_value=httpx.Response(401))

    with pytest.raises(httpx.HTTPStatusError):
        await ObixClient(BASE, "admin", "bad").read("/obix/config/")
