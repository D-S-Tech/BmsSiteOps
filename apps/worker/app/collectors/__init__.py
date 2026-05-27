"""Data-source collector framework.

Every external source of operational telemetry (Tactical RMM, Tridium Niagara
via Fox, BACnet/IP, future Modbus / SNMP / etc.) implements the `Collector`
abstract base class defined in `base.py`. The collector scheduler invokes
`discover()` and `poll()` according to each source's configured cadence, and
collectors push events into Redis pub/sub for the Laravel side to persist
and trigger downstream behavior.

Stubs currently included:

- TrmmCollector       — Tactical RMM REST API
- NiagaraCollector    — Tridium Niagara Fox protocol
- BacnetCollector     — BACnet/IP via bacpypes3

Each stub satisfies the `Collector` interface but does not yet contact the
real service. Sprint 1 (TRMM), Sprint 2 (Niagara), and a later sprint
(BACnet) replace the stubs with real implementations.
"""

from app.collectors.bacnet import BacnetCollector
from app.collectors.base import (
    Collector,
    CollectorConfig,
    CollectorEvent,
    CollectorKind,
)
from app.collectors.niagara import NiagaraCollector
from app.collectors.trmm import TrmmCollector

__all__ = [
    "BacnetCollector",
    "Collector",
    "CollectorConfig",
    "CollectorEvent",
    "CollectorKind",
    "NiagaraCollector",
    "TrmmCollector",
]
