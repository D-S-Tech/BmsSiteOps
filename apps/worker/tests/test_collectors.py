"""Tests for the Collector abstract base class and its stub subclasses.

These tests verify the interface contract: every collector subclass must
expose the right `kind`, must accept a `CollectorConfig`, and must satisfy
the abstract methods (even if those methods return empty results in the
Sprint 0 stubs).
"""

from __future__ import annotations

from datetime import UTC, datetime

import pytest

from app.collectors import (
    BacnetCollector,
    Collector,
    CollectorConfig,
    CollectorEvent,
    CollectorKind,
    NiagaraCollector,
    TrmmCollector,
)


def _config(kind: CollectorKind) -> CollectorConfig:
    """Build a minimal valid config for the given kind."""
    return CollectorConfig(
        source_id=1,
        tenant_id=1,
        site_id=1,
        kind=kind,
        name=f"test-{kind.value}",
        base_url="http://example.invalid",
        credentials={"token": "fake"},
        poll_interval_seconds=60,
    )


class TestCollectorConfig:
    def test_config_is_immutable(self) -> None:
        """CollectorConfig is frozen — fields cannot be mutated after creation."""
        cfg = _config(CollectorKind.TRMM)
        with pytest.raises(ValueError):  # ValidationError is a ValueError subclass
            cfg.name = "different"  # type: ignore[misc]

    def test_config_rejects_extra_fields(self) -> None:
        """extra='forbid' — unknown fields fail validation."""
        with pytest.raises(ValueError):
            CollectorConfig(
                source_id=1,
                tenant_id=1,
                site_id=1,
                kind=CollectorKind.TRMM,
                name="x",
                unknown_field="boom",  # type: ignore[call-arg]
            )


class TestCollectorEvent:
    def test_event_rejects_extra_fields(self) -> None:
        """CollectorEvent is also strict."""
        with pytest.raises(ValueError):
            CollectorEvent(
                source_id=1,
                tenant_id=1,
                site_id=1,
                kind=CollectorKind.TRMM,
                device_external_id="dev-001",
                timestamp=datetime.now(UTC),
                metric="temperature",
                value=72.5,
                extra="boom",  # type: ignore[call-arg]
            )

    def test_event_accepts_metadata(self) -> None:
        """Arbitrary metadata is allowed under the typed `metadata` field."""
        event = CollectorEvent(
            source_id=1,
            tenant_id=1,
            site_id=1,
            kind=CollectorKind.NIAGARA,
            device_external_id="AHU-1",
            timestamp=datetime.now(UTC),
            metric="discharge_temp",
            value=55.2,
            metadata={"units": "F", "quality": "good"},
        )
        assert event.metadata["units"] == "F"


@pytest.mark.parametrize(
    ("subclass", "kind"),
    [
        (TrmmCollector, CollectorKind.TRMM),
        (NiagaraCollector, CollectorKind.NIAGARA),
        (BacnetCollector, CollectorKind.BACNET),
    ],
)
class TestCollectorStructure:
    """Structural contract every collector subclass must satisfy."""

    def test_subclass_is_a_collector(self, subclass: type[Collector], kind: CollectorKind) -> None:
        assert issubclass(subclass, Collector)

    def test_subclass_kind_matches_class_attribute(
        self, subclass: type[Collector], kind: CollectorKind
    ) -> None:
        assert kind == subclass.KIND

    async def test_subclass_instance_carries_config(
        self, subclass: type[Collector], kind: CollectorKind
    ) -> None:
        cfg = _config(kind)
        collector = subclass(cfg)
        assert collector.config is cfg
        assert collector.kind == kind
