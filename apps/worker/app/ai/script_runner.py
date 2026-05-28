"""ScriptRunner — drains the Laravel script queue.

Loop:
  1. POST /internal/scripts/claim    -> get the next Requested script
  2. Call ScriptGenerator.generate() -> ScriptGenResult
  3. POST /internal/scripts/{id}/result with the result payload

Run as a one-shot (run_once / drain) from a cron-like scheduler, or wrap in a
sleep-loop for a long-running worker. This module is unopinionated about how
it's invoked — same shape as SyncRunner (collectors) and BriefRunner.

Error handling: any exception during generation is converted to a 'failed'
ScriptGenResult by the generator itself, so the runner always submits *some*
result and the script never gets stuck in Generating. The only thing that
*can* fail at the runner level is the submit_result HTTP call — that's
logged and re-raised so the caller can decide.
"""

from __future__ import annotations

import logging
from typing import Any

from app.ai.scripts import ScriptGenerator
from app.clients.scripts import ScriptsClient

log = logging.getLogger(__name__)


class ScriptRunner:
    def __init__(
        self,
        client: ScriptsClient,
        generator: ScriptGenerator,
    ) -> None:
        self._client = client
        self._generator = generator

    async def run_once(self) -> dict[str, Any] | None:
        """Process at most one script. Returns the claimed script payload
        (with the submit result merged under 'result'), or None when the
        queue was empty.
        """
        claimed = await self._client.claim_next()
        if claimed is None:
            return None

        script_id = int(claimed["id"])
        log.info(
            "Claimed script id=%s language=%s title=%r",
            script_id,
            claimed.get("language"),
            claimed.get("title"),
        )

        result = await self._generator.generate(claimed)
        submitted = await self._client.submit_result(
            script_id,
            **result.to_payload(),
        )
        return {"claimed": claimed, "result": submitted}

    async def drain(self, *, max_items: int = 50) -> int:
        """Repeatedly run_once() until the queue is empty or max_items reached.

        Returns the count of scripts processed.
        """
        processed = 0
        while processed < max_items:
            outcome = await self.run_once()
            if outcome is None:
                break
            processed += 1
        return processed
