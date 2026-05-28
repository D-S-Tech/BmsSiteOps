"""MCP tool definitions and implementations.

Pure async functions: each takes a LaravelClient + tool-specific arguments
and returns a JSON-serializable result. The MCP server wiring in server.py
exposes them with proper input schemas. The functions themselves are
unit-tested with a fake LaravelClient — no `mcp` import required for tests.

Tools exposed:
  bmssiteops_list_sites    — enumerate all sites in the tenant
  bmssiteops_site_overview — summary + latest AI brief for one site
  bmssiteops_ask           — ask a question; returns answer + citations
  bmssiteops_create_script — request an AI-authored script

Each `bmssiteops_*_definition()` returns the JSON Schema input schema +
human-readable description for the MCP `list_tools` handshake.
"""

from __future__ import annotations

from typing import Any

from app.mcp.laravel_client import LaravelClient

# ---------------------------------------------------------------------------
# Tool definitions (name -> {description, input_schema})
# ---------------------------------------------------------------------------


TOOL_DEFINITIONS: dict[str, dict[str, Any]] = {
    "bmssiteops_list_sites": {
        "description": (
            "List all sites in the operator's BmsSiteOps tenant. Returns "
            "site id, slug, name, address, and key metadata."
        ),
        "input_schema": {
            "type": "object",
            "properties": {
                "per_page": {
                    "type": "integer",
                    "description": "Max sites to return (default 25, max 100).",
                    "default": 25,
                    "minimum": 1,
                    "maximum": 100,
                }
            },
            "required": [],
            "additionalProperties": False,
        },
    },
    "bmssiteops_site_overview": {
        "description": (
            "Return a single site's summary (device count, alert state, etc.) "
            "plus its most recent AI-generated site brief, if any."
        ),
        "input_schema": {
            "type": "object",
            "properties": {
                "site_id": {
                    "type": "integer",
                    "description": "Numeric site id (use bmssiteops_list_sites to find it).",
                }
            },
            "required": ["site_id"],
            "additionalProperties": False,
        },
    },
    "bmssiteops_ask": {
        "description": (
            "Ask a Site Q&A question. The platform runs RAG over the "
            "operator's knowledge base and returns an answer grounded in "
            "their actual documents, with citations."
        ),
        "input_schema": {
            "type": "object",
            "properties": {
                "question": {
                    "type": "string",
                    "description": "The question to ask. 3-5000 characters.",
                    "minLength": 3,
                    "maxLength": 5000,
                },
                "site_id": {
                    "type": "integer",
                    "description": (
                        "Optional site to scope the search to. Omit to search "
                        "across all tenant documents."
                    ),
                },
            },
            "required": ["question"],
            "additionalProperties": False,
        },
    },
    "bmssiteops_create_script": {
        "description": (
            "Request an AI-authored script. Languages include python, "
            "javascript, typescript, shell, esphome_yaml, nodered_flow, "
            "bacnet_config, niagara_program, and generic. The worker picks "
            "it up asynchronously; poll bmssiteops_list_sites's tenant for "
            "the result in the operator's panel."
        ),
        "input_schema": {
            "type": "object",
            "properties": {
                "title": {"type": "string", "description": "Short title.", "maxLength": 200},
                "prompt": {
                    "type": "string",
                    "description": "Describe what the script should do.",
                    "maxLength": 5000,
                },
                "language": {
                    "type": "string",
                    "enum": [
                        "python",
                        "javascript",
                        "typescript",
                        "shell",
                        "esphome_yaml",
                        "nodered_flow",
                        "bacnet_config",
                        "niagara_program",
                        "generic",
                    ],
                },
            },
            "required": ["title", "prompt", "language"],
            "additionalProperties": False,
        },
    },
}


# ---------------------------------------------------------------------------
# Tool implementations
# ---------------------------------------------------------------------------


async def list_sites(client: LaravelClient, *, per_page: int = 25) -> dict[str, Any]:
    sites = await client.list_sites(per_page=per_page)
    return {"sites": sites, "count": len(sites)}


async def site_overview(client: LaravelClient, *, site_id: int) -> dict[str, Any]:
    return await client.site_overview(site_id)


async def ask(
    client: LaravelClient,
    *,
    question: str,
    site_id: int | None = None,
) -> dict[str, Any]:
    return await client.ask(question, site_id=site_id)


async def create_script(
    client: LaravelClient,
    *,
    title: str,
    prompt: str,
    language: str,
) -> dict[str, Any]:
    return await client.create_script(title=title, prompt=prompt, language=language)


# ---------------------------------------------------------------------------
# Dispatcher — used by server.py
# ---------------------------------------------------------------------------


async def dispatch_tool(
    client: LaravelClient,
    name: str,
    arguments: dict[str, Any],
) -> dict[str, Any]:
    """Route an MCP call_tool invocation to the right implementation."""
    if name == "bmssiteops_list_sites":
        return await list_sites(client, **arguments)
    if name == "bmssiteops_site_overview":
        return await site_overview(client, **arguments)
    if name == "bmssiteops_ask":
        return await ask(client, **arguments)
    if name == "bmssiteops_create_script":
        return await create_script(client, **arguments)
    raise ValueError(f"Unknown MCP tool: {name!r}")
