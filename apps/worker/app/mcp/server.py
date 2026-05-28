"""MCP server wiring.

Builds an `mcp.server.Server` instance and registers our four tools. Mounts
on the FastAPI app via the SSE transport at /mcp/sse + /mcp/messages/.

Imports of the `mcp` package are kept inside the build_* functions so that
`app.mcp.tools` and `app.mcp.laravel_client` remain importable even in
environments where `mcp` isn't installed (CI test envs that only exercise
the tool dispatcher). The SSE handshake against a live MCP client is
integration-only and flagged 'needs validation'.
"""

from __future__ import annotations

import json
from typing import TYPE_CHECKING, Any

from app.mcp.laravel_client import LaravelClient
from app.mcp.tools import TOOL_DEFINITIONS, dispatch_tool

if TYPE_CHECKING:
    from mcp.server import Server


def build_server(client: LaravelClient) -> Server:
    """Construct the MCP Server instance with all tools registered.

    Imported lazily so tests don't pay the mcp-package import cost.
    """
    from mcp.server import Server
    from mcp.types import TextContent, Tool

    server = Server("bmssiteops")

    @server.list_tools()  # type: ignore[no-untyped-call, untyped-decorator]
    async def list_tools_handler() -> list[Tool]:
        return [
            Tool(
                name=name,
                description=spec["description"],
                inputSchema=spec["input_schema"],
            )
            for name, spec in TOOL_DEFINITIONS.items()
        ]

    @server.call_tool()  # type: ignore[untyped-decorator]
    async def call_tool_handler(name: str, arguments: dict[str, Any]) -> list[TextContent]:
        result = await dispatch_tool(client, name, arguments)
        return [TextContent(type="text", text=json.dumps(result, indent=2, default=str))]

    return server


def mount_on_fastapi(app: Any, client: LaravelClient, *, path_prefix: str = "/mcp") -> None:
    """Mount the MCP SSE transport on a FastAPI/Starlette app.

    Adds two endpoints:
      GET  {path_prefix}/sse        — SSE event stream
      POST {path_prefix}/messages/  — JSON-RPC POSTs from the client

    Lazily imports `mcp` — if it's not installed, this raises ImportError
    with a clear message rather than crashing at app startup.
    """
    try:
        from mcp.server.sse import SseServerTransport
    except ImportError as exc:  # pragma: no cover - dependency present in CI
        raise ImportError(
            "The 'mcp' package is required for MCP server support. "
            "Install it with `uv add mcp` in apps/worker."
        ) from exc

    from starlette.routing import Mount, Route

    server = build_server(client)
    transport = SseServerTransport(f"{path_prefix}/messages/")

    async def handle_sse(request: Any) -> None:  # pragma: no cover - integration
        async with transport.connect_sse(request.scope, request.receive, request._send) as (
            read_stream,
            write_stream,
        ):
            await server.run(read_stream, write_stream, server.create_initialization_options())

    app.routes.append(Route(f"{path_prefix}/sse", endpoint=handle_sse))
    app.routes.append(Mount(f"{path_prefix}/messages/", app=transport.handle_post_message))
