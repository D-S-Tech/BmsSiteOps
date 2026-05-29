#!/usr/bin/env python3
"""BmsSiteOps MCP SSE smoke test.

Validates the full MCP server end-to-end:

  1. SSE handshake against ${MCP_BASE_URL}/sse
  2. MCP initialize round trip
  3. list_tools returns the four expected bmssiteops_* tools
  4. call_tool bmssiteops_list_sites returns a parseable result

Exit codes:
  0  smoke passed
  1  env var missing / import error
  2  SSE connection failed
  3  initialize handshake failed
  4  list_tools missing expected tools
  5  call_tool returned an error or unparseable result

Run after every prod-deploy that touches the worker:

    MCP_BASE_URL=https://ops-mcp.bmssiteops.com \\
    uv run --project apps/worker python infra/scripts/mcp-smoke.py

Or via Makefile:

    make mcp-smoke
"""

from __future__ import annotations

import asyncio
import json
import os
import sys
from typing import Any


# --- Config --------------------------------------------------------------------

MCP_BASE_URL = os.environ.get("MCP_BASE_URL", "").rstrip("/")
TIMEOUT_SEC = float(os.environ.get("MCP_TIMEOUT_SEC", "30"))

EXPECTED_TOOLS = {
    "bmssiteops_list_sites",
    "bmssiteops_site_overview",
    "bmssiteops_ask",
    "bmssiteops_create_script",
}


# --- Logging -------------------------------------------------------------------


def log(msg: str) -> None:
    print(f"  \033[36m·\033[0m {msg}", file=sys.stderr)


def ok(msg: str) -> None:
    print(f"  \033[32m✓\033[0m {msg}", file=sys.stderr)


def fail(msg: str) -> None:
    print(f"  \033[31m✗\033[0m {msg}", file=sys.stderr)


# --- Main ----------------------------------------------------------------------


async def smoke() -> int:
    if not MCP_BASE_URL:
        fail("MCP_BASE_URL is required (e.g. https://ops-mcp.bmssiteops.com)")
        return 1

    try:
        from mcp import ClientSession
        from mcp.client.sse import sse_client
    except ImportError as e:
        fail(f"mcp SDK not importable: {e}. Run with: uv run --project apps/worker python ...")
        return 1

    sse_url = f"{MCP_BASE_URL}/sse"
    print(f"\n\033[1mBmsSiteOps MCP smoke test\033[0m")
    print(f"   MCP: {sse_url}\n")

    # --- 1. Connect to SSE -----------------------------------------------------
    log("connecting to SSE...")
    try:
        async with asyncio.timeout(TIMEOUT_SEC):
            async with sse_client(sse_url) as (read_stream, write_stream):
                ok("SSE stream established")

                # --- 2. Initialize handshake -----------------------------------
                log("initializing MCP session...")
                async with ClientSession(read_stream, write_stream) as session:
                    try:
                        init = await session.initialize()
                    except Exception as e:
                        fail(f"initialize handshake failed: {e!r}")
                        return 3

                    ok(
                        f"initialized with server: "
                        f"{init.serverInfo.name if init.serverInfo else '?'}"
                    )

                    # --- 3. list_tools ----------------------------------------
                    log("listing tools...")
                    tools_resp = await session.list_tools()
                    tool_names = {t.name for t in tools_resp.tools}
                    missing = EXPECTED_TOOLS - tool_names
                    if missing:
                        fail(f"missing expected tools: {sorted(missing)}")
                        fail(f"  got: {sorted(tool_names)}")
                        return 4
                    extra = tool_names - EXPECTED_TOOLS
                    if extra:
                        log(f"(found extra tools: {sorted(extra)})")
                    ok(f"all {len(EXPECTED_TOOLS)} expected tools present")

                    # --- 4. call_tool bmssiteops_list_sites -------------------
                    log("calling bmssiteops_list_sites...")
                    try:
                        result = await session.call_tool(
                            "bmssiteops_list_sites", {"per_page": 5}
                        )
                    except Exception as e:
                        fail(f"call_tool raised: {e!r}")
                        return 5

                    if result.isError:
                        fail(f"call_tool returned error: {result.content!r}")
                        return 5

                    # Tool returns a TextContent with JSON inside.
                    if not result.content:
                        fail("call_tool returned empty content")
                        return 5

                    try:
                        text = getattr(result.content[0], "text", None)
                        if not text:
                            fail("call_tool result has no text payload")
                            return 5
                        payload: dict[str, Any] = json.loads(text)
                    except (json.JSONDecodeError, AttributeError) as e:
                        fail(f"call_tool result not parseable as JSON: {e}")
                        return 5

                    if "sites" not in payload or "count" not in payload:
                        fail(f"call_tool result missing expected keys: {payload!r}")
                        return 5

                    ok(f"bmssiteops_list_sites returned {payload['count']} site(s)")

    except TimeoutError:
        fail(f"timed out after {TIMEOUT_SEC}s")
        return 2
    except Exception as e:  # noqa: BLE001 — surface any other transport-layer failure
        fail(f"SSE transport error: {e!r}")
        return 2

    print(f"\n\033[1;32m✓ MCP smoke test passed\033[0m\n")
    return 0


if __name__ == "__main__":
    sys.exit(asyncio.run(smoke()))
