"""Fox transport interface for Niagara.

Defines the seam between the Niagara collector's Fox mapping (which this
project owns and unit-tests) and the proprietary Fox wire protocol (which is
reverse-engineered and can only be trusted after validation against a real
JACE). The collector depends only on `FoxTransport` + `FoxPoint`, so its
fox-path mapping is fully testable with a fake transport.

This module imports nothing network-related — it is pure data + interface.

Niagara status strings (BStatus) drive device status and event severity, the
same way oBIX status does on the other Niagara transport.
"""

from __future__ import annotations

from abc import ABC, abstractmethod
from dataclasses import dataclass, field
from typing import Any

# Niagara BStatus flags that mean the point is unreachable/not in service.
FOX_DOWN_STATUSES = frozenset({"down", "disabled"})

# BStatus flags indicating an active alarm condition.
FOX_ALARM_STATUSES = frozenset({"alarm", "unackedAlarm"})

# BStatus flags indicating a degraded but non-alarm condition.
FOX_ALERT_STATUSES = frozenset({"fault", "stale", "alert", "overridden"})


@dataclass
class FoxPoint:
    """A control point read from a Niagara station over Fox."""

    handle: str  # component ORD / slot path — stable identifier
    name: str
    value: Any
    status: str = "ok"  # Niagara BStatus string
    units: str | None = None
    metadata: dict[str, Any] = field(default_factory=dict)


def fox_device_status(status: str) -> str:
    """Map a Niagara BStatus to a device status."""
    return "offline" if status in FOX_DOWN_STATUSES else "online"


def fox_event_severity(status: str) -> str | None:
    """Map a Niagara BStatus to an event severity."""
    if status in FOX_ALARM_STATUSES:
        return "critical"
    if status in FOX_ALERT_STATUSES:
        return "warning"
    return None


class FoxTransport(ABC):
    """Async interface to a Niagara station over Fox."""

    @abstractmethod
    async def read_points(self) -> list[FoxPoint]:
        """Return the station's monitored points with current values."""

    @abstractmethod
    async def close(self) -> None:
        """Release the Fox session / socket."""
