"""HTTP client for the oBIX (Open Building Information Exchange) interface.

oBIX is an OASIS standard that Niagara stations expose over HTTP, representing
the station's object tree as XML. This client reads oBIX objects and parses
them into a small, transport-neutral structure the Niagara collector maps into
devices and events.

Authentication is HTTP Basic (username/password from the source credentials).
XML is parsed namespace-tolerantly — Niagara may or may not declare the oBIX
namespace, so local element names are used throughout.

Reference: oBIX 1.1 (OASIS). Value-bearing elements are real, bool, int, str,
enum, abstime, reltime; containers are obj, list, ref.
"""

from __future__ import annotations

from dataclasses import dataclass, field
from xml.etree import ElementTree

import httpx

# oBIX element tags that carry a scalar value (a "point").
VALUE_TAGS = frozenset({"real", "bool", "int", "str", "enum", "abstime", "reltime"})

# oBIX status values that indicate the object is unreachable/dead.
DOWN_STATUSES = frozenset({"down", "disabled"})

# oBIX status values that indicate an active alarm/alert condition.
ALARM_STATUSES = frozenset({"alarm", "unackedAlarm"})
ALERT_STATUSES = frozenset({"alert", "unackedAlert", "fault"})


def _localname(tag: str) -> str:
    """Strip any XML namespace, returning the bare element name."""
    return tag.rsplit("}", 1)[-1] if "}" in tag else tag


@dataclass
class ObixObject:
    """A parsed oBIX element."""

    tag: str
    name: str | None = None
    href: str | None = None
    display_name: str | None = None
    val: str | None = None
    status: str = "ok"
    unit: str | None = None
    children: list[ObixObject] = field(default_factory=list)

    @property
    def is_point(self) -> bool:
        """True if this element carries a scalar value (a monitorable point)."""
        return self.tag in VALUE_TAGS

    def display(self) -> str:
        """Best available human label for the object."""
        return self.display_name or self.name or self.href or self.tag


def parse_obix(xml: str) -> ObixObject:
    """Parse an oBIX XML document into an ObixObject tree."""
    root = ElementTree.fromstring(xml)
    return _to_object(root)


def _to_object(element: ElementTree.Element) -> ObixObject:
    attrs = element.attrib
    # Niagara encodes the unit as e.g. "obix:units/fahrenheit" — keep the leaf.
    unit = attrs.get("unit")
    if unit:
        unit = unit.rsplit("/", 1)[-1]

    obj = ObixObject(
        tag=_localname(element.tag),
        name=attrs.get("name"),
        href=attrs.get("href"),
        display_name=attrs.get("displayName"),
        val=attrs.get("val"),
        status=attrs.get("status", "ok"),
        unit=unit,
    )
    obj.children = [_to_object(child) for child in element]
    return obj


class ObixClient:
    """Async oBIX client for a single Niagara station."""

    def __init__(
        self,
        base_url: str,
        username: str,
        password: str,
        *,
        timeout: float = 30.0,
        client: httpx.AsyncClient | None = None,
    ) -> None:
        self._base_url = base_url.rstrip("/")
        self._username = username
        self._password = password
        self._timeout = timeout
        self._client = client

    def _make_client(self) -> httpx.AsyncClient:
        if self._client is not None:
            return self._client
        return httpx.AsyncClient(
            base_url=self._base_url,
            auth=(self._username, self._password),
            headers={"Accept": "text/xml"},
            timeout=self._timeout,
        )

    async def read(self, href: str) -> ObixObject:
        """GET an oBIX object by href and parse it."""
        path = href if href.startswith("/") else f"/{href}"
        client = self._make_client()
        owns_client = self._client is None
        try:
            response = await client.get(path)
            response.raise_for_status()
            return parse_obix(response.text)
        finally:
            if owns_client:
                await client.aclose()
