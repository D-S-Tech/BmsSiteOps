"""Tests for SiteBriefGenerator — prompt building (pure) and generation."""

from __future__ import annotations

from typing import Any

from app.ai.llm import FakeLLMClient
from app.ai.site_brief import SiteBriefGenerator


def _context() -> dict[str, Any]:
    return {
        "site": {"id": 4, "name": "80 Pine St", "address": "80 Pine St, NYC"},
        "period": {
            "start": "2026-05-27T13:00:00+00:00",
            "end": "2026-05-28T13:00:00+00:00",
            "hours": 24,
        },
        "devices": {"total": 12, "online": 9, "offline": 2, "unknown": 1, "muted": 1},
        "sources": {"total": 3, "ok": 2, "error": 1, "never": 0},
        "events": {"total": 340, "critical": 3, "warning": 15, "info": 322, "none": 0},
        "timeline": [],
        "recent_events": [
            {
                "metric": "discharge_temp",
                "value": "92",
                "severity": "critical",
                "occurred_at": "2026-05-28T12:30:00+00:00",
            }
        ],
    }


def test_build_prompt_includes_key_facts() -> None:
    gen = SiteBriefGenerator(FakeLLMClient(), model="claude-sonnet-4-5")
    prompt = gen.build_prompt(_context())

    assert "80 Pine St" in prompt
    assert "12 total" in prompt  # devices
    assert "2 offline" in prompt
    assert "1 muted" in prompt
    assert "3 critical" in prompt  # events
    assert "discharge_temp=92" in prompt  # recent event
    assert "[critical]" in prompt


def test_build_prompt_handles_no_recent_events() -> None:
    ctx = _context()
    ctx["recent_events"] = []
    gen = SiteBriefGenerator(FakeLLMClient(), model="m")
    prompt = gen.build_prompt(ctx)
    assert "No recent critical or warning events." in prompt


async def test_generate_produces_result_from_llm() -> None:
    fake = FakeLLMClient(response_text="  Two critical alerts on AHU-1.  ", output_tokens=33)
    gen = SiteBriefGenerator(fake, model="claude-sonnet-4-5")

    result = await gen.generate(_context())

    # Summary is the trimmed LLM text.
    assert result.summary == "Two critical alerts on AHU-1."
    assert result.model == "claude-sonnet-4-5"
    assert result.period_start == "2026-05-27T13:00:00+00:00"
    assert result.period_end == "2026-05-28T13:00:00+00:00"
    assert result.metadata["output_tokens"] == 33
    assert result.metadata["snapshot"]["events"]["critical"] == 3

    # The generator passed the system + built prompt to the LLM.
    assert len(fake.calls) == 1
    assert "operations analyst" in fake.calls[0].system
    assert "80 Pine St" in fake.calls[0].user


async def test_to_payload_has_store_endpoint_fields() -> None:
    gen = SiteBriefGenerator(FakeLLMClient(response_text="ok"), model="m")
    result = await gen.generate(_context())
    payload = result.to_payload()

    assert set(payload.keys()) == {
        "summary",
        "model",
        "period_start",
        "period_end",
        "generated_at",
        "metadata",
    }
