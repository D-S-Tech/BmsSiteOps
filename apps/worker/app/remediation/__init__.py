"""Out-of-band remediation actions executed by the worker.

This module is the worker-side counterpart to the Laravel TriageActionExecutor.
Laravel handles actions it can perform itself (mute_device, mark_for_review,
ignore). Actions that require network access to external systems (TRMM, BMS
controllers) belong here — the worker is the only thing in the architecture
that can make those calls.

For Sprint 5.3 this is foundation only: the seam is in place and unit-tested,
the TRMM transport is implemented and respx-tested at the request level, but
no production wiring exists yet from Laravel triage to the worker. A future
sprint will add a queue + a new TriageAction value (e.g. RestartTrmmAgent)
that drops a job here.

Honest by construction: the FAKE transport makes the dispatcher fully testable;
the REAL TRMM transport's request building is unit-tested with respx; the
live TRMM call itself is integration-only and not in CI.
"""

from app.remediation.base import (
    FakeRemediationTransport,
    RemediationAction,
    RemediationResult,
    RemediationTransport,
)
from app.remediation.dispatcher import RemediationDispatcher, UnknownActionError
from app.remediation.trmm import TrmmRemediationTransport

__all__ = [
    "FakeRemediationTransport",
    "RemediationAction",
    "RemediationDispatcher",
    "RemediationResult",
    "RemediationTransport",
    "TrmmRemediationTransport",
    "UnknownActionError",
]
