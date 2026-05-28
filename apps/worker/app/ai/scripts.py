"""AI script generation.

Mirrors the SiteBriefGenerator pattern from Sprint 4: the build_prompt /
extract_code halves are pure functions and exhaustively unit-tested; only
generate() actually calls the LLM and is tested end-to-end with a FakeLLMClient.

The transport is the SAME LLM seam used for Site Briefs — LiteLLM with a
different `model` string. The expected production wiring is:

    model = "ollama/qwen2.5-coder:32b"        # local Ollama (BOLDNJPC, RX 7900 XTX)
    llm   = LiteLlmClient(proxy_url, api_key) # talks to LiteLLM
    gen   = ScriptGenerator(llm, model=model)

LiteLLM proxy routes the "ollama/..." prefix to the local Ollama server. From
the worker's point of view, there is one uniform LLM interface — the routing
choice is config, not code.

For the live Ollama call, the same integration-only posture as for the
Anthropic call applies: respx-tested at the request shape; the actual
inference is a deployment-time concern.
"""

from __future__ import annotations

import hashlib
import re
from dataclasses import dataclass
from typing import Any

from app.ai.llm import LLMClient

# Languages where wrapping the prompt with BMS-specific context is worth it.
# Generic / shell / typescript fall back to a more neutral system prompt.
_BMS_FLAVORS = {
    "esphome_yaml",
    "nodered_flow",
    "bacnet_config",
    "niagara_program",
}


@dataclass(frozen=True)
class ScriptGenResult:
    """The outcome of one generation, ready to submit back to Laravel."""

    status: str  # "ready" | "failed"
    content: str | None
    model: str
    error: str | None
    metadata: dict[str, Any]

    def to_payload(self) -> dict[str, Any]:
        payload: dict[str, Any] = {"status": self.status, "model": self.model}
        if self.content is not None:
            payload["content"] = self.content
        if self.error is not None:
            payload["error"] = self.error
        if self.metadata:
            payload["metadata"] = self.metadata
        return payload


class ScriptGenerator:
    """Builds an LLM prompt for a script request and parses the response.

    Construct once and reuse — instances are stateless.
    """

    def __init__(
        self,
        llm: LLMClient,
        model: str,
        *,
        max_tokens: int = 2048,
    ) -> None:
        self._llm = llm
        self._model = model
        self._max_tokens = max_tokens

    # --- prompt building (pure) ---------------------------------------------

    def system_prompt(self, language: str) -> str:
        """Role + output-shape instruction, varied by target language.

        Returns a single string suitable for the LLM's system message.
        """
        base = (
            "You are a senior automation engineer writing utility code for a BMS / HVAC / "
            "MEP operations platform. Output ONLY the requested code — no prose, no "
            "explanations, no markdown fences. The user will paste the result directly "
            "into a file, so the response must be a valid, runnable artifact of the "
            "requested language."
        )
        if language in _BMS_FLAVORS:
            base += (
                " Where the artifact is configuration (ESPHome YAML, Node-RED JSON, "
                "Niagara program, BACnet object list), assume sensible defaults for "
                "BMS networks (BACnet/IP on UDP 47808, MS/TP at 38400, common point "
                "naming conventions) and call out any value the operator must edit."
            )
        return base

    def build_user_prompt(self, script: dict[str, Any]) -> str:
        """Render the script row into a user message.

        Includes the language hint, the operator title, and the prompt body —
        in that order so the model sees the format constraint first.
        """
        language = str(script.get("language", "generic"))
        title = str(script.get("title", "Script")).strip()
        prompt = str(script.get("prompt", "")).strip()

        lines = [
            f"Target language / format: {language}",
            f"Task: {title}",
            "",
            "Request:",
            prompt,
        ]
        return "\n".join(lines)

    # --- response shaping (pure) --------------------------------------------

    _FENCE_RE = re.compile(
        # Optional opening fence with an optional language tag, the body
        # (lazy), and an optional closing fence.
        r"^```[a-zA-Z0-9_+-]*\s*\n(.*?)\n?```\s*$",
        re.DOTALL,
    )

    def extract_code(self, raw: str) -> str:
        """Strip a single wrapping ``` fence if the model added one.

        Defensive — the system prompt asks for no fences, but small models
        occasionally add them anyway. Leaves inner fences intact, only
        peels the outermost wrapping.
        """
        text = raw.strip()
        match = self._FENCE_RE.match(text)
        if match is not None:
            return match.group(1).strip()
        return text

    def prompt_digest(self, script: dict[str, Any]) -> str:
        """Stable hex digest of the prompt body (debug / metadata)."""
        joined = f"{script.get('language')}|{script.get('title')}|{script.get('prompt')}"
        return hashlib.sha256(joined.encode("utf-8")).hexdigest()[:16]

    # --- actual generation ---------------------------------------------------

    async def generate(self, script: dict[str, Any]) -> ScriptGenResult:
        """Call the LLM and shape the result for /internal/scripts/{id}/result.

        Exceptions from the LLM transport are caught and surfaced as a
        failed ScriptGenResult — this way the runner can always submit
        *something* back to Laravel.
        """
        language = str(script.get("language", "generic"))
        system = self.system_prompt(language)
        user = self.build_user_prompt(script)

        try:
            response = await self._llm.complete(
                model=self._model,
                system=system,
                user=user,
                max_tokens=self._max_tokens,
            )
        except Exception as exc:
            return ScriptGenResult(
                status="failed",
                content=None,
                model=self._model,
                error=f"LLM transport error: {exc!r}",
                metadata={"prompt_digest": self.prompt_digest(script)},
            )

        content = self.extract_code(response.text or "")
        if not content:
            return ScriptGenResult(
                status="failed",
                content=None,
                model=self._model,
                error="LLM returned empty content",
                metadata={
                    "prompt_digest": self.prompt_digest(script),
                    "input_tokens": response.input_tokens,
                    "output_tokens": response.output_tokens,
                },
            )

        return ScriptGenResult(
            status="ready",
            content=content,
            model=self._model,
            error=None,
            metadata={
                "prompt_digest": self.prompt_digest(script),
                "input_tokens": response.input_tokens,
                "output_tokens": response.output_tokens,
            },
        )
