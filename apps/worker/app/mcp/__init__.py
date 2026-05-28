"""MCP-server side of the worker.

A thin Model Context Protocol server that lets external agents (Claude
Desktop, Claude Code, the platform's own internal agent loops) call into
BmsSiteOps. The tools are plain async functions in `tools.py` — fully
unit-testable with a mocked Laravel client — and `server.py` wires them
into an `mcp.server.Server` instance with the SSE transport.

The SSE handshake against a live MCP client is integration-only and is
flagged 'needs validation' in the README, same posture as LLM / embedding /
TRMM / TimescaleDB / pgvector live calls. The tools themselves are tested
exhaustively.
"""
