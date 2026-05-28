"""Tests for BriefRunner — fetch context, generate, push, end to end."""

from __future__ import annotations

from typing import Any

from app.ai.brief_runner import BriefRunner
from app.ai.llm import FakeLLMClient
from app.ai.site_brief import SiteBriefGenerator
from app.clients.brief import BriefClient

_CONTEXT: dict[str, Any] = {
    "site": {"id": 4, "name": "80 Pine St"},
    "period": {
        "start": "2026-05-27T13:00:00+00:00",
        "end": "2026-05-28T13:00:00+00:00",
        "hours": 24,
    },
    "devices": {"total": 5, "online": 5, "offline": 0, "unknown": 0, "muted": 0},
    "sources": {"total": 1, "ok": 1, "error": 0, "never": 0},
    "events": {"total": 10, "critical": 0, "warning": 1, "info": 9, "none": 0},
    "timeline": [],
    "recent_events": [],
}


class FakeBriefClient(BriefClient):
    """In-memory BriefClient: canned context, records the pushed brief."""

    def __init__(self) -> None:
        super().__init__("http://unused", "unused")
        self.pushed: tuple[int, dict[str, Any]] | None = None

    async def fetch_context(self, site_id: int, hours: int = 24) -> dict[str, Any]:
        return {**_CONTEXT, "_requested_hours": hours}

    async def push_brief(self, site_id: int, payload: dict[str, Any]) -> dict[str, Any]:
        self.pushed = (site_id, payload)
        return {"data": {"id": 99, **payload}}


async def test_run_for_site_generates_and_pushes() -> None:
    client = FakeBriefClient()
    generator = SiteBriefGenerator(
        FakeLLMClient(response_text="Quiet day; one warning."),
        model="claude-sonnet-4-5",
    )
    runner = BriefRunner(client, generator)

    result = await runner.run_for_site(4, hours=24)

    # The brief was pushed for the right site with a generated summary.
    assert client.pushed is not None
    site_id, payload = client.pushed
    assert site_id == 4
    assert payload["summary"] == "Quiet day; one warning."
    assert payload["model"] == "claude-sonnet-4-5"
    assert payload["period_start"] == "2026-05-27T13:00:00+00:00"
    assert "input_tokens" in payload["metadata"]

    # The stored brief JSON is returned to the caller.
    assert result["data"]["id"] == 99
