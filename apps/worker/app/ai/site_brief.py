"""AI Site Brief generation.

Turns the site context (from the Laravel brief-context endpoint) into a short
natural-language brief via an LLM. build_prompt() is pure and unit-tested;
generate() runs the LLM through the injected LLMClient seam, so the whole
generator is testable with FakeLLMClient — no network required.
"""

from __future__ import annotations

import json
from dataclasses import dataclass
from datetime import UTC, datetime
from typing import Any

from app.ai.llm import LLMClient

SYSTEM_PROMPT = (
    "You are an operations analyst for a building-management and IT monitoring "
    "platform. Given a structured snapshot of one site (device health, data-"
    "source health, event-severity counts, an hourly event timeline, and the "
    "most recent actionable events), write a concise daily brief for an on-call "
    "operator. Lead with the single most important thing. Call out anything "
    "critical or trending worse. If everything is nominal, say so plainly and "
    "briefly. Do not invent data not present in the snapshot. Keep it under 180 "
    "words, plain prose, no markdown headers."
)


@dataclass(frozen=True)
class SiteBriefResult:
    """A generated brief, ready to push to the Laravel store endpoint."""

    summary: str
    model: str
    period_start: str
    period_end: str
    generated_at: str
    metadata: dict[str, Any]

    def to_payload(self) -> dict[str, Any]:
        return {
            "summary": self.summary,
            "model": self.model,
            "period_start": self.period_start,
            "period_end": self.period_end,
            "generated_at": self.generated_at,
            "metadata": self.metadata,
        }


class SiteBriefGenerator:
    """Builds the prompt and produces a SiteBriefResult from site context."""

    def __init__(self, llm: LLMClient, model: str, *, max_tokens: int = 1024) -> None:
        self._llm = llm
        self._model = model
        self._max_tokens = max_tokens

    def build_prompt(self, context: dict[str, Any]) -> str:
        """Render the context snapshot into a deterministic user prompt."""
        site = context.get("site", {})
        period = context.get("period", {})
        devices = context.get("devices", {})
        sources = context.get("sources", {})
        events = context.get("events", {})
        recent = context.get("recent_events", [])
        triage = context.get("triage_24h", {})

        lines = [
            f"Site: {site.get('name', 'Unknown')} (id {site.get('id', '?')})",
            f"Window: {period.get('start', '?')} to {period.get('end', '?')} "
            f"({period.get('hours', '?')}h)",
            "",
            "Devices: "
            f"{devices.get('total', 0)} total, "
            f"{devices.get('online', 0)} online, "
            f"{devices.get('offline', 0)} offline, "
            f"{devices.get('unknown', 0)} unknown, "
            f"{devices.get('muted', 0)} muted",
            "Data sources: "
            f"{sources.get('total', 0)} total, "
            f"{sources.get('ok', 0)} ok, "
            f"{sources.get('error', 0)} error, "
            f"{sources.get('never', 0)} never-synced",
            "Events in window: "
            f"{events.get('total', 0)} total, "
            f"{events.get('critical', 0)} critical, "
            f"{events.get('warning', 0)} warning, "
            f"{events.get('info', 0)} info",
        ]

        # Only mention automated triage when something actually happened in the
        # window — keeps the brief tight when no rules fired.
        if triage.get("total", 0) > 0:
            lines.append(
                "Automated triage in window: "
                f"{triage.get('total', 0)} decisions "
                f"({triage.get('executed', 0)} executed, "
                f"{triage.get('failed', 0)} failed, "
                f"{triage.get('skipped', 0)} skipped)"
            )

        lines.append("")

        if recent:
            lines.append("Most recent actionable events:")
            for e in recent[:10]:
                lines.append(
                    f"- [{e.get('severity', '?')}] {e.get('metric', '?')}="
                    f"{e.get('value', '?')} at {e.get('occurred_at', '?')}"
                )
        else:
            lines.append("No recent critical or warning events.")

        return "\n".join(lines)

    async def generate(self, context: dict[str, Any]) -> SiteBriefResult:
        """Call the LLM and shape the result for storage."""
        user_prompt = self.build_prompt(context)
        response = await self._llm.complete(
            system=SYSTEM_PROMPT,
            user=user_prompt,
            model=self._model,
            max_tokens=self._max_tokens,
        )

        period = context.get("period", {})
        now = datetime.now(UTC).isoformat()

        return SiteBriefResult(
            summary=response.text.strip(),
            model=response.model,
            period_start=period.get("start", now),
            period_end=period.get("end", now),
            generated_at=now,
            metadata={
                "input_tokens": response.input_tokens,
                "output_tokens": response.output_tokens,
                "snapshot": {
                    "devices": context.get("devices", {}),
                    "events": context.get("events", {}),
                },
            },
        )

    def context_digest(self, context: dict[str, Any]) -> str:
        """Compact JSON of the context (handy for logging/debugging)."""
        return json.dumps(context, separators=(",", ":"), default=str)
