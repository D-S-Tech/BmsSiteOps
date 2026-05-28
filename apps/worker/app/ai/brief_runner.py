"""Daily AI Site Brief runner.

Orchestrates one site's brief: fetch context from the Laravel internal API,
generate the brief via the LLM, push it back. Fully testable with a fake
BriefClient + FakeLLMClient — no network, no live LiteLLM.
"""

from __future__ import annotations

from typing import Any

from app.ai.site_brief import SiteBriefGenerator
from app.clients.brief import BriefClient


class BriefRunner:
    """Generates and stores a Site Brief for a single site."""

    def __init__(
        self,
        brief_client: BriefClient,
        generator: SiteBriefGenerator,
    ) -> None:
        self._brief_client = brief_client
        self._generator = generator

    async def run_for_site(self, site_id: int, *, hours: int = 24) -> dict[str, Any]:
        """Fetch context -> generate -> push. Returns the stored brief JSON."""
        context = await self._brief_client.fetch_context(site_id, hours)
        result = await self._generator.generate(context)
        return await self._brief_client.push_brief(site_id, result.to_payload())
