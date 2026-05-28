"""BACnet transport interface.

Defines the seam between the BACnet collector (which owns the device/event
mapping) and the underlying BACnet/IP stack (which owns protocol correctness).

The collector depends only on the `BacnetTransport` ABC and the small
dataclasses below, so its mapping logic is fully unit-testable with a fake
transport. The real implementation (`Bacpypes3Transport`) lives in
`bacnet_bacpypes.py` and is imported lazily, keeping the heavy bacpypes3
dependency out of the unit-test path.

This module imports NO BACnet library — it is pure data + interface.
"""

from __future__ import annotations

from abc import ABC, abstractmethod
from dataclasses import dataclass, field
from typing import Any

# BACnet object types that carry a monitorable present-value.
VALUE_OBJECT_TYPES = frozenset(
    {
        "analogInput",
        "analogOutput",
        "analogValue",
        "binaryInput",
        "binaryOutput",
        "binaryValue",
        "multiStateInput",
        "multiStateOutput",
        "multiStateValue",
    }
)


@dataclass
class BacnetDevice:
    """A discovered BACnet device (controller)."""

    device_id: int
    address: str
    name: str | None = None
    vendor_id: int | None = None
    model_name: str | None = None


@dataclass
class BacnetPoint:
    """A value-bearing BACnet object read from a device."""

    object_type: str
    instance: int
    present_value: Any
    name: str | None = None
    units: str | None = None
    in_alarm: bool = False
    fault: bool = False
    out_of_service: bool = False
    metadata: dict[str, Any] = field(default_factory=dict)

    @property
    def identifier(self) -> str:
        """Stable per-device identifier, e.g. 'analogInput:3'."""
        return f"{self.object_type}:{self.instance}"


class BacnetTransport(ABC):
    """Async interface to a BACnet/IP internetwork."""

    @abstractmethod
    async def who_is(self) -> list[BacnetDevice]:
        """Broadcast Who-Is and collect I-Am responses into devices."""

    @abstractmethod
    async def read_points(self, device: BacnetDevice) -> list[BacnetPoint]:
        """Read the value-bearing objects of a device with present-value."""

    @abstractmethod
    async def close(self) -> None:
        """Release any networking resources."""
