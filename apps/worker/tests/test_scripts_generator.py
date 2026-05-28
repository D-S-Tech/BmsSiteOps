"""Tests for ScriptGenerator — pure prompt building, extract_code, and the
generate() flow against a FakeLLMClient.
"""

from __future__ import annotations

from typing import Any

from app.ai.llm import FakeLLMClient, LLMClient, LLMResponse
from app.ai.scripts import ScriptGenerator, ScriptGenResult


def _script(**overrides: Any) -> dict[str, Any]:
    base = {
        "id": 7,
        "title": "List online TRMM agents",
        "prompt": "Use httpx to call TRMM /api/agents/ and print the names of online ones.",
        "language": "python",
    }
    base.update(overrides)
    return base


# ---------------------------------------------------------------------------
# system_prompt / build_user_prompt (pure)
# ---------------------------------------------------------------------------


def test_system_prompt_includes_role_and_format_constraint() -> None:
    gen = ScriptGenerator(FakeLLMClient(), model="m")
    sp = gen.system_prompt("python")
    assert "automation engineer" in sp.lower()
    assert "no markdown fences" in sp.lower() or "no markdown" in sp.lower()


def test_system_prompt_adds_bms_flavor_for_bms_languages() -> None:
    gen = ScriptGenerator(FakeLLMClient(), model="m")
    python_sp = gen.system_prompt("python")
    esphome_sp = gen.system_prompt("esphome_yaml")
    bacnet_sp = gen.system_prompt("bacnet_config")

    # BMS-flavored prompts mention concrete BMS facts the model otherwise would not.
    assert "BACnet" in esphome_sp
    assert "47808" in esphome_sp
    assert "BACnet" in bacnet_sp
    # Non-BMS language gets the leaner prompt.
    assert "47808" not in python_sp


def test_build_user_prompt_includes_language_title_and_prompt_body() -> None:
    gen = ScriptGenerator(FakeLLMClient(), model="m")
    up = gen.build_user_prompt(_script())
    assert "Target language / format: python" in up
    assert "Task: List online TRMM agents" in up
    assert "httpx" in up  # the operator's prompt body


# ---------------------------------------------------------------------------
# extract_code (pure)
# ---------------------------------------------------------------------------


def test_extract_code_returns_unfenced_text_as_is() -> None:
    gen = ScriptGenerator(FakeLLMClient(), model="m")
    assert gen.extract_code("print('hi')\n") == "print('hi')"


def test_extract_code_strips_outer_fence_with_language_tag() -> None:
    gen = ScriptGenerator(FakeLLMClient(), model="m")
    raw = "```python\nprint('hi')\n```"
    assert gen.extract_code(raw) == "print('hi')"


def test_extract_code_strips_outer_fence_without_language_tag() -> None:
    gen = ScriptGenerator(FakeLLMClient(), model="m")
    raw = "```\nprint('hi')\n```"
    assert gen.extract_code(raw) == "print('hi')"


def test_extract_code_leaves_inner_fences_alone() -> None:
    gen = ScriptGenerator(FakeLLMClient(), model="m")
    # Output is a markdown doc that itself contains a fenced snippet — we
    # should NOT strip the inner fence.
    raw = "# Title\n\n```python\nprint('hi')\n```\n\nText after."
    assert gen.extract_code(raw) == raw  # no outer fence to strip


# ---------------------------------------------------------------------------
# prompt_digest (pure)
# ---------------------------------------------------------------------------


def test_prompt_digest_is_deterministic_and_changes_with_inputs() -> None:
    gen = ScriptGenerator(FakeLLMClient(), model="m")
    a = gen.prompt_digest(_script())
    b = gen.prompt_digest(_script())
    c = gen.prompt_digest(_script(prompt="different"))
    assert a == b
    assert a != c
    assert len(a) == 16


# ---------------------------------------------------------------------------
# generate() — happy path with FakeLLMClient
# ---------------------------------------------------------------------------


async def test_generate_returns_ready_with_extracted_content() -> None:
    fake = FakeLLMClient(
        response_text="```python\nimport httpx\nprint('ok')\n```",
        input_tokens=200,
        output_tokens=30,
    )
    gen = ScriptGenerator(fake, model="ollama/qwen2.5-coder:32b")

    result = await gen.generate(_script())

    assert isinstance(result, ScriptGenResult)
    assert result.status == "ready"
    assert result.content == "import httpx\nprint('ok')"
    assert result.model == "ollama/qwen2.5-coder:32b"
    assert result.error is None
    assert result.metadata["input_tokens"] == 200
    assert result.metadata["output_tokens"] == 30
    assert len(result.metadata["prompt_digest"]) == 16

    # Recorded one LLM call with system + user prompts.
    assert len(fake.calls) == 1
    call = fake.calls[0]
    assert "automation engineer" in call.system.lower()
    assert "List online TRMM agents" in call.user


async def test_generate_returns_failed_when_llm_returns_empty() -> None:
    fake = FakeLLMClient(response_text="")
    gen = ScriptGenerator(fake, model="m")

    result = await gen.generate(_script())

    assert result.status == "failed"
    assert result.content is None
    assert "empty" in (result.error or "")


# ---------------------------------------------------------------------------
# generate() — LLM transport raises
# ---------------------------------------------------------------------------


class _RaisingLLMClient(LLMClient):
    async def complete(
        self, *, system: str, user: str, model: str, max_tokens: int = 1024
    ) -> LLMResponse:
        raise RuntimeError("connection refused")


async def test_generate_catches_llm_transport_errors_as_failed() -> None:
    gen = ScriptGenerator(_RaisingLLMClient(), model="m")

    result = await gen.generate(_script())

    assert result.status == "failed"
    assert result.content is None
    assert "connection refused" in (result.error or "")
    # The metadata still carries the prompt digest so we can correlate.
    assert "prompt_digest" in result.metadata


# ---------------------------------------------------------------------------
# to_payload shape
# ---------------------------------------------------------------------------


def test_to_payload_ready_includes_content_and_omits_error() -> None:
    result = ScriptGenResult(
        status="ready",
        content="x = 1",
        model="m",
        error=None,
        metadata={"tokens": 5},
    )
    payload = result.to_payload()
    assert payload == {
        "status": "ready",
        "content": "x = 1",
        "model": "m",
        "metadata": {"tokens": 5},
    }


def test_to_payload_failed_includes_error_and_omits_content() -> None:
    result = ScriptGenResult(status="failed", content=None, model="m", error="boom", metadata={})
    payload = result.to_payload()
    assert payload == {"status": "failed", "model": "m", "error": "boom"}
