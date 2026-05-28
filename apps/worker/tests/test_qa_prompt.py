"""Pure-function tests for app/qa/__init__.py prompt building."""

from __future__ import annotations

from app.qa import SYSTEM_PROMPT, build_user_prompt


def test_system_prompt_mentions_grounding_and_citations() -> None:
    """The system prompt must contain the two non-negotiable rules: ground in
    context and admit when the context doesn't answer.
    """
    assert "Ground every factual claim" in SYSTEM_PROMPT
    assert "context doesn't cover" in SYSTEM_PROMPT


def test_build_user_prompt_renders_numbered_contexts() -> None:
    rendered = build_user_prompt(
        "When does AHU-1 start?",
        [
            {
                "content": "AHU-1 starts when OAT > 55F.",
                "document_title": "80 Pine St SOO",
                "score": 0.92,
            },
            {
                "content": "VRF system is independent.",
                "document_title": "VRF spec",
                "score": 0.74,
            },
        ],
    )

    assert "Question: When does AHU-1 start?" in rendered
    assert '[1] from "80 Pine St SOO" (similarity 0.920)' in rendered
    assert "AHU-1 starts when OAT > 55F." in rendered
    assert '[2] from "VRF spec" (similarity 0.740)' in rendered


def test_build_user_prompt_handles_missing_document_title() -> None:
    rendered = build_user_prompt(
        "test?",
        [{"content": "snippet", "score": 0.5}],
    )
    assert "(untitled document)" in rendered


def test_build_user_prompt_with_no_contexts_says_so() -> None:
    rendered = build_user_prompt("anything?", [])
    assert "(no context retrieved)" in rendered
    assert "Answer:" in rendered


def test_build_user_prompt_trims_question() -> None:
    rendered = build_user_prompt("   spaced  out?   ", [])
    assert "Question: spaced  out?" in rendered


def test_build_user_prompt_handles_missing_score() -> None:
    """A context without a score should still render — just without the similarity line."""
    rendered = build_user_prompt("q?", [{"content": "c", "document_title": "T"}])
    assert '[1] from "T"' in rendered
    assert "similarity" not in rendered
