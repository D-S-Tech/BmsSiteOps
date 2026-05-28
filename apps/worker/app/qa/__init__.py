"""Q&A prompt construction.

Pure functions — no LLM calls, no I/O, no time, no DB. Fully unit-tested.
The actual LLM call lives in app/qa/endpoints.py (which uses the LLM seam
from Sprint 4).

The system prompt orients the model toward HVAC/MEP operations and forces
it to ground every claim in the provided context (or to admit it can't
answer). The user prompt numbers each context block so the model can refer
to "[1]", "[2]", etc. in citations.
"""

from __future__ import annotations

from typing import Any

SYSTEM_PROMPT = """You are the BmsSiteOps Site Q&A assistant — an expert HVAC, BMS, and MEP \
operations advisor. You answer questions about specific buildings using the operator's own \
knowledge base: commissioning documents, sequences of operation, vendor specs, and recent \
AI site briefs.

Hard rules:
1. Ground every factual claim in the provided context. If the context doesn't cover the \
question, say so explicitly and suggest what the operator should look up.
2. When you cite, name the source document by its title in plain prose — for example, \
"per the 80 Pine St mech room SOO".
3. Be terse and precise. Operators are field engineers; skip the throat-clearing.
4. If you spot a contradiction between two context blocks, surface it rather than \
silently picking one.
5. Numbers, model numbers, and BACnet point names must be quoted verbatim from the \
context — never invented."""


def build_user_prompt(question: str, contexts: list[dict[str, Any]]) -> str:
    """Render the user message: the question plus numbered context blocks.

    Each context entry is {content: str, document_title: str | None, score: float}.
    """
    lines: list[str] = ["Question: " + question.strip(), "", "Context:"]
    if not contexts:
        lines.append("(no context retrieved)")
    for i, ctx in enumerate(contexts, start=1):
        title = ctx.get("document_title") or "(untitled document)"
        score = ctx.get("score")
        header = f'[{i}] from "{title}"'
        if isinstance(score, (int, float)):
            header += f" (similarity {score:.3f})"
        lines.append(header)
        lines.append((ctx.get("content") or "").strip())
        lines.append("")
    lines.append("Answer:")
    return "\n".join(lines)
