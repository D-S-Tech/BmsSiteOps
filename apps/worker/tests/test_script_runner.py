"""Tests for ScriptRunner — orchestration with fake client + fake LLM."""

from __future__ import annotations

from typing import Any

import pytest

from app.ai.llm import FakeLLMClient
from app.ai.script_runner import ScriptRunner
from app.ai.scripts import ScriptGenerator
from app.clients.scripts import ScriptsClient


class _FakeScriptsClient(ScriptsClient):
    """In-memory ScriptsClient — yields canned claims, records submits."""

    def __init__(self, claims: list[dict[str, Any] | None]) -> None:
        # Skip the real __init__; we don't make HTTP calls.
        self._claims = list(claims)
        self.submits: list[dict[str, Any]] = []

    async def claim_next(self) -> dict[str, Any] | None:
        if not self._claims:
            return None
        return self._claims.pop(0)

    async def submit_result(
        self,
        script_id: int,
        *,
        status: str,
        content: str | None = None,
        model: str | None = None,
        error: str | None = None,
        metadata: dict[str, Any] | None = None,
    ) -> dict[str, Any]:
        record = {
            "script_id": script_id,
            "status": status,
            "content": content,
            "model": model,
            "error": error,
            "metadata": metadata,
        }
        self.submits.append(record)
        return {"data": {"id": script_id, "status": status}}


def _claimed_script(**overrides: Any) -> dict[str, Any]:
    base = {
        "id": 11,
        "title": "Echo hello",
        "prompt": "Write a Python one-liner that prints 'hello'.",
        "language": "python",
        "status": "generating",
    }
    base.update(overrides)
    return base


@pytest.mark.asyncio
async def test_run_once_returns_none_when_queue_is_empty() -> None:
    client = _FakeScriptsClient(claims=[None])
    gen = ScriptGenerator(FakeLLMClient(), model="m")
    runner = ScriptRunner(client, gen)

    result = await runner.run_once()

    assert result is None
    assert client.submits == []


@pytest.mark.asyncio
async def test_run_once_claims_generates_and_submits_ready() -> None:
    fake_llm = FakeLLMClient(response_text="print('hello')")
    client = _FakeScriptsClient(claims=[_claimed_script(id=11)])
    runner = ScriptRunner(client, ScriptGenerator(fake_llm, model="ollama/qwen2.5-coder:32b"))

    outcome = await runner.run_once()

    assert outcome is not None
    assert outcome["claimed"]["id"] == 11

    assert len(client.submits) == 1
    sub = client.submits[0]
    assert sub["script_id"] == 11
    assert sub["status"] == "ready"
    assert sub["content"] == "print('hello')"
    assert sub["model"] == "ollama/qwen2.5-coder:32b"
    assert sub["error"] is None
    assert sub["metadata"] is not None
    assert sub["metadata"]["input_tokens"] == 100  # FakeLLMClient default


@pytest.mark.asyncio
async def test_run_once_submits_failed_when_llm_returns_empty() -> None:
    client = _FakeScriptsClient(claims=[_claimed_script(id=12)])
    runner = ScriptRunner(client, ScriptGenerator(FakeLLMClient(response_text=""), model="m"))

    await runner.run_once()

    assert len(client.submits) == 1
    sub = client.submits[0]
    assert sub["script_id"] == 12
    assert sub["status"] == "failed"
    assert sub["content"] is None
    assert "empty" in (sub["error"] or "")


@pytest.mark.asyncio
async def test_drain_processes_all_queued_scripts() -> None:
    client = _FakeScriptsClient(
        claims=[
            _claimed_script(id=1),
            _claimed_script(id=2),
            _claimed_script(id=3),
            None,  # queue is empty after the 3rd
        ]
    )
    runner = ScriptRunner(client, ScriptGenerator(FakeLLMClient(response_text="x"), model="m"))

    processed = await runner.drain()

    assert processed == 3
    assert [s["script_id"] for s in client.submits] == [1, 2, 3]


@pytest.mark.asyncio
async def test_drain_respects_max_items() -> None:
    client = _FakeScriptsClient(claims=[_claimed_script(id=i) for i in range(10)])
    runner = ScriptRunner(client, ScriptGenerator(FakeLLMClient(response_text="x"), model="m"))

    processed = await runner.drain(max_items=4)

    assert processed == 4
    assert len(client.submits) == 4
