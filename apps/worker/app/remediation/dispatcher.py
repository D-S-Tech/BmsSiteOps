"""Route remediation actions to the right transport.

The dispatcher knows how to map an action.kind to the transport that handles
it. Transports are registered by the action kinds they support. This is the
public entry point for worker code that wants to perform a remediation —
callers don't construct transports directly.

Failure modes are explicit:
  * UnknownActionError when no transport claims the action kind. (Distinct
    from a transport returning failed — that means the call was attempted
    and the remote rejected it.)
"""

from __future__ import annotations

from collections.abc import Iterable

from app.remediation.base import (
    RemediationAction,
    RemediationResult,
    RemediationTransport,
)


class UnknownActionError(Exception):
    """Raised when no registered transport handles an action's kind."""


class RemediationDispatcher:
    """Maps action.kind -> RemediationTransport and runs the call.

    Construct with a mapping from kind to transport instance:

        dispatcher = RemediationDispatcher({"restart_trmm_agent": trmm})
        result = await dispatcher.dispatch(action)
    """

    def __init__(self, transports: dict[str, RemediationTransport]) -> None:
        # Defensive copy so callers can't mutate the routing table after
        # construction.
        self._transports = dict(transports)

    @property
    def supported_kinds(self) -> Iterable[str]:
        return self._transports.keys()

    async def dispatch(self, action: RemediationAction) -> RemediationResult:
        transport = self._transports.get(action.kind)
        if transport is None:
            raise UnknownActionError(f"No transport registered for action kind {action.kind!r}")
        return await transport.execute(action)
