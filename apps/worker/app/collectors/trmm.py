"""Tactical RMM collector.

Pulls agents (→ devices) and alerts (→ events) from a TRMM instance and
normalizes them into the platform's shapes. TRMM polling is one-shot per
cycle: `poll()` fetches the current unresolved alerts, yields one event each,
and completes; the runner re-invokes it on the source's poll interval.

Field mapping
-------------
agent.agent_id          -> device.external_id
agent.hostname          -> device.name
agent.monitoring_type   -> device.type        (server | workstation)
agent.status            -> device.status      (online | offline)
agent.last_seen         -> device.last_seen_at
agent.operating_system  -> device.metadata.os

alert.agent_id          -> event.device_external_id
alert.message           -> event.value
alert.severity          -> event.severity     (info | warning | critical)
alert.created_time      -> event.occurred_at
"""

from __future__ import annotations

from collections.abc import AsyncIterator
from datetime import UTC, datetime
from typing import Any

from app.clients.trmm import TrmmClient
from app.collectors.base import Collector, CollectorEvent, CollectorKind


def _parse_dt(value: Any) -> datetime:
    """Parse a TRMM timestamp into an aware UTC datetime, tolerating None."""
    if not value:
        return datetime.now(UTC)
    if isinstance(value, datetime):
        return value if value.tzinfo else value.replace(tzinfo=UTC)
    text = str(value).replace("Z", "+00:00")
    try:
        dt = datetime.fromisoformat(text)
    except ValueError:
        return datetime.now(UTC)
    return dt if dt.tzinfo else dt.replace(tzinfo=UTC)


def _map_device_status(status: Any) -> str:
    """TRMM status (online | offline | overdue) -> normalized device status."""
    match str(status).lower():
        case "online":
            return "online"
        case "offline" | "overdue":
            return "offline"
        case _:
            return "unknown"


def _map_device_type(monitoring_type: Any) -> str | None:
    value = str(monitoring_type).lower() if monitoring_type else ""
    if value in ("server", "workstation"):
        return value
    return None


def _map_severity(severity: Any) -> str | None:
    """TRMM alert severity -> normalized event severity."""
    match str(severity).lower():
        case "info":
            return "info"
        case "warning":
            return "warning"
        case "error" | "critical":
            return "critical"
        case _:
            return None


class TrmmCollector(Collector):
    """Collects devices and events from a Tactical RMM instance."""

    KIND: CollectorKind = CollectorKind.TRMM

    def _client(self) -> TrmmClient:
        return TrmmClient(
            base_url=self.config.base_url or "",
            api_key=self.config.credentials.get("api_token", ""),
        )

    async def discover(self) -> list[dict[str, Any]]:
        """List TRMM agents and normalize them into device descriptors."""
        agents = await self._client().list_agents()

        devices: list[dict[str, Any]] = []
        for agent in agents:
            external_id = agent.get("agent_id")
            if external_id is None:
                continue
            devices.append(
                {
                    "external_id": str(external_id),
                    "name": agent.get("hostname") or str(external_id),
                    "type": _map_device_type(agent.get("monitoring_type")),
                    "status": _map_device_status(agent.get("status")),
                    "last_seen_at": agent.get("last_seen"),
                    "metadata": {
                        "os": agent.get("operating_system"),
                        "plat": agent.get("plat"),
                    },
                }
            )
        return devices

    async def poll(self) -> AsyncIterator[CollectorEvent]:
        """Fetch current unresolved alerts and yield one event each."""
        alerts = await self._client().list_alerts(resolved=False)

        for alert in alerts:
            agent_id = alert.get("agent_id") or alert.get("agent")
            if agent_id is None:
                continue
            yield CollectorEvent(
                source_id=self.config.source_id,
                tenant_id=self.config.tenant_id,
                site_id=self.config.site_id,
                kind=CollectorKind.TRMM,
                device_external_id=str(agent_id),
                timestamp=_parse_dt(alert.get("created_time")),
                metric="alert",
                value=alert.get("message"),
                severity=_map_severity(alert.get("severity")),
                metadata={
                    "alert_type": alert.get("alert_type"),
                    "trmm_alert_id": alert.get("id"),
                },
            )
