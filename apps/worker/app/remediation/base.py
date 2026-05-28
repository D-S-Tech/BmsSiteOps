"""Remediation transport interface + fake.

Defines the seam between the dispatcher (which the worker owns and tests) and
the proprietary system being remediated. Every concrete transport implements
`execute(action)` and returns a deterministic `RemediationResult`.
"""

from __future__ import annotations

from abc import ABC, abstractmethod
from dataclasses import dataclass, field
from typing import Any


@dataclass(frozen=True)
class RemediationAction:
    """One remediation request to execute."""

    kind: str  # e.g. "restart_trmm_agent"
    params: dict[str, Any] = field(default_factory=dict)


@dataclass(frozen=True)
class RemediationResult:
    """The outcome of one remediation call.

    Status mirrors the Laravel TriageStatus values so a future cross-process
    flow can persist this directly without translation:
        - "executed"  call succeeded
        - "failed"    the transport raised or the remote returned an error
        - "skipped"   the transport intentionally did nothing (e.g. dry-run)
    """

    status: str
    message: str | None = None
    result: dict[str, Any] | None = None


class RemediationTransport(ABC):
    """Async interface to one remediation backend (TRMM, BMS, ...)."""

    @abstractmethod
    async def execute(self, action: RemediationAction) -> RemediationResult:
        """Perform the action; return a deterministic result."""


class FakeRemediationTransport(RemediationTransport):
    """In-memory transport for tests — records actions, returns a canned result."""

    def __init__(
        self,
        *,
        result: RemediationResult | None = None,
        raises: Exception | None = None,
    ) -> None:
        self._canned = result or RemediationResult(status="executed")
        self._raises = raises
        self.calls: list[RemediationAction] = []

    async def execute(self, action: RemediationAction) -> RemediationResult:
        self.calls.append(action)
        if self._raises is not None:
            raise self._raises
        return self._canned
