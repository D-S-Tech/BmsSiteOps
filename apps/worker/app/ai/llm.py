"""LLM client seam.

The brief generator depends only on the LLMClient interface, so its prompt-
building and result-shaping logic is fully unit-tested with FakeLLMClient. The
real LiteLLM-backed implementation lives in litellm_client.py and is an
integration concern (it needs a running LiteLLM proxy).
"""

from __future__ import annotations

from abc import ABC, abstractmethod
from dataclasses import dataclass


@dataclass(frozen=True)
class LLMResponse:
    """A completion result with token accounting."""

    text: str
    model: str
    input_tokens: int = 0
    output_tokens: int = 0


class LLMClient(ABC):
    """Minimal chat-completion interface."""

    @abstractmethod
    async def complete(
        self,
        *,
        system: str,
        user: str,
        model: str,
        max_tokens: int = 1024,
    ) -> LLMResponse:
        """Return a completion for a system + user prompt."""


@dataclass
class _RecordedCall:
    system: str
    user: str
    model: str
    max_tokens: int


class FakeLLMClient(LLMClient):
    """In-memory LLMClient for tests — returns canned text, records calls."""

    def __init__(
        self,
        response_text: str = "Test brief.",
        *,
        input_tokens: int = 100,
        output_tokens: int = 20,
    ) -> None:
        self._response_text = response_text
        self._input_tokens = input_tokens
        self._output_tokens = output_tokens
        self.calls: list[_RecordedCall] = []

    async def complete(
        self,
        *,
        system: str,
        user: str,
        model: str,
        max_tokens: int = 1024,
    ) -> LLMResponse:
        self.calls.append(
            _RecordedCall(system=system, user=user, model=model, max_tokens=max_tokens)
        )
        return LLMResponse(
            text=self._response_text,
            model=model,
            input_tokens=self._input_tokens,
            output_tokens=self._output_tokens,
        )
