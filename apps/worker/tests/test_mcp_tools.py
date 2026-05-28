"""Tests for the MCP tools layer.

Covers two surfaces:
  1. LaravelClient request shape (which URL, headers, body) via respx.
  2. dispatch_tool routing + each tool's pass-through of arguments.

The MCP server wiring itself (mcp.server.Server + SSE transport) is
imported lazily and is integration-only — the SSE handshake against a
real MCP client is validated by hand. These tests don't import `mcp`.
"""

from __future__ import annotations

import httpx
import pytest
import respx

from app.mcp import tools as tools_module
from app.mcp.laravel_client import LaravelClient

# ---------------------------------------------------------------------------
# LaravelClient — request shape
# ---------------------------------------------------------------------------


@pytest.fixture()
def base() -> str:
    return "https://api.example.com"


@pytest.mark.asyncio
@respx.mock
async def test_list_sites_GETs_v1_sites_with_bearer(base: str) -> None:
    route = respx.get(f"{base}/api/v1/sites").mock(
        return_value=httpx.Response(
            200,
            json={
                "data": [
                    {"id": 1, "slug": "site-a", "name": "Site A"},
                    {"id": 2, "slug": "site-b", "name": "Site B"},
                ]
            },
        )
    )

    async with httpx.AsyncClient(base_url=base) as inner:
        c = LaravelClient(base, "test-token", client=inner)
        sites = await c.list_sites()

    assert route.called
    assert route.calls[0].request.headers["authorization"] == "Bearer test-token"
    assert route.calls[0].request.headers["accept"] == "application/json"
    assert len(sites) == 2
    assert sites[0]["slug"] == "site-a"


@pytest.mark.asyncio
@respx.mock
async def test_list_sites_passes_per_page_query_param(base: str) -> None:
    route = respx.get(f"{base}/api/v1/sites").mock(
        return_value=httpx.Response(200, json={"data": []})
    )

    async with httpx.AsyncClient(base_url=base) as inner:
        c = LaravelClient(base, "t", client=inner)
        await c.list_sites(per_page=50)

    assert route.calls[0].request.url.params["per_page"] == "50"


@pytest.mark.asyncio
@respx.mock
async def test_site_overview_combines_summary_and_latest_brief(base: str) -> None:
    summary_route = respx.get(f"{base}/api/v1/sites/7/summary").mock(
        return_value=httpx.Response(200, json={"data": {"site_id": 7, "device_count": 12}})
    )
    briefs_route = respx.get(f"{base}/api/v1/sites/7/briefs").mock(
        return_value=httpx.Response(
            200,
            json={
                "data": [
                    {"id": 99, "summary": "All quiet on the Mech Room front"},
                ]
            },
        )
    )

    async with httpx.AsyncClient(base_url=base) as inner:
        c = LaravelClient(base, "t", client=inner)
        result = await c.site_overview(7)

    assert summary_route.called
    assert briefs_route.called
    assert briefs_route.calls[0].request.url.params["per_page"] == "1"
    assert result["summary"]["device_count"] == 12
    assert result["latest_brief"]["id"] == 99


@pytest.mark.asyncio
@respx.mock
async def test_site_overview_returns_none_for_brief_when_empty(base: str) -> None:
    respx.get(f"{base}/api/v1/sites/7/summary").mock(
        return_value=httpx.Response(200, json={"data": {"site_id": 7}})
    )
    respx.get(f"{base}/api/v1/sites/7/briefs").mock(
        return_value=httpx.Response(200, json={"data": []})
    )

    async with httpx.AsyncClient(base_url=base) as inner:
        c = LaravelClient(base, "t", client=inner)
        result = await c.site_overview(7)

    assert result["latest_brief"] is None


@pytest.mark.asyncio
@respx.mock
async def test_ask_posts_qa_with_optional_site_id(base: str) -> None:
    route = respx.post(f"{base}/api/v1/qa").mock(
        return_value=httpx.Response(
            201,
            json={
                "data": {
                    "id": 1,
                    "question": "test?",
                    "answer": "test answer",
                    "status": "ready",
                }
            },
        )
    )

    async with httpx.AsyncClient(base_url=base) as inner:
        c = LaravelClient(base, "t", client=inner)
        result = await c.ask("test?", site_id=42)

    import json as _json

    body = _json.loads(route.calls[0].request.content)
    assert body == {"question": "test?", "site_id": 42}
    assert result["answer"] == "test answer"


@pytest.mark.asyncio
@respx.mock
async def test_ask_omits_site_id_when_not_provided(base: str) -> None:
    route = respx.post(f"{base}/api/v1/qa").mock(
        return_value=httpx.Response(201, json={"data": {"id": 1, "status": "ready"}})
    )

    async with httpx.AsyncClient(base_url=base) as inner:
        c = LaravelClient(base, "t", client=inner)
        await c.ask("q?")

    import json as _json

    body = _json.loads(route.calls[0].request.content)
    assert body == {"question": "q?"}
    assert "site_id" not in body


@pytest.mark.asyncio
@respx.mock
async def test_create_script_posts_v1_scripts(base: str) -> None:
    route = respx.post(f"{base}/api/v1/scripts").mock(
        return_value=httpx.Response(
            201,
            json={
                "data": {
                    "id": 1,
                    "title": "List sites",
                    "language": "python",
                    "status": "requested",
                }
            },
        )
    )

    async with httpx.AsyncClient(base_url=base) as inner:
        c = LaravelClient(base, "t", client=inner)
        result = await c.create_script(
            title="List sites", prompt="Print site names.", language="python"
        )

    import json as _json

    body = _json.loads(route.calls[0].request.content)
    assert body == {
        "title": "List sites",
        "prompt": "Print site names.",
        "language": "python",
    }
    assert result["status"] == "requested"


# ---------------------------------------------------------------------------
# Tool definitions + dispatch_tool
# ---------------------------------------------------------------------------


def test_tool_definitions_cover_all_four_tools() -> None:
    expected = {
        "bmssiteops_list_sites",
        "bmssiteops_site_overview",
        "bmssiteops_ask",
        "bmssiteops_create_script",
    }
    assert set(tools_module.TOOL_DEFINITIONS.keys()) == expected


def test_tool_definitions_each_have_description_and_schema() -> None:
    for name, spec in tools_module.TOOL_DEFINITIONS.items():
        assert isinstance(spec["description"], str) and spec["description"], name
        assert spec["input_schema"]["type"] == "object", name
        # additionalProperties is set to False on all tools to keep the LLM honest.
        assert spec["input_schema"]["additionalProperties"] is False, name


def test_create_script_schema_enumerates_supported_languages() -> None:
    schema = tools_module.TOOL_DEFINITIONS["bmssiteops_create_script"]["input_schema"]
    languages = schema["properties"]["language"]["enum"]
    # Same nine values as App\Enums\ScriptLanguage.
    assert set(languages) == {
        "python",
        "javascript",
        "typescript",
        "shell",
        "esphome_yaml",
        "nodered_flow",
        "bacnet_config",
        "niagara_program",
        "generic",
    }


class _FakeLaravelClient:
    """Records calls; returns canned dicts. Used to test dispatch_tool."""

    def __init__(self) -> None:
        self.calls: list[tuple[str, tuple, dict]] = []

    async def list_sites(self, per_page: int = 25) -> list[dict]:
        self.calls.append(("list_sites", (), {"per_page": per_page}))
        return [{"id": 1, "name": "A"}, {"id": 2, "name": "B"}]

    async def site_overview(self, site_id: int) -> dict:
        self.calls.append(("site_overview", (), {"site_id": site_id}))
        return {"summary": {"site_id": site_id}, "latest_brief": None}

    async def ask(self, question: str, site_id: int | None = None) -> dict:
        self.calls.append(("ask", (), {"question": question, "site_id": site_id}))
        return {"answer": "stub"}

    async def create_script(self, title: str, prompt: str, language: str) -> dict:
        self.calls.append(
            (
                "create_script",
                (),
                {"title": title, "prompt": prompt, "language": language},
            )
        )
        return {"id": 1, "status": "requested"}


@pytest.mark.asyncio
async def test_dispatch_tool_routes_list_sites() -> None:
    fake = _FakeLaravelClient()
    result = await tools_module.dispatch_tool(
        fake,
        "bmssiteops_list_sites",
        {"per_page": 10},  # type: ignore[arg-type]
    )
    assert result["count"] == 2
    assert fake.calls[0] == ("list_sites", (), {"per_page": 10})


@pytest.mark.asyncio
async def test_dispatch_tool_routes_site_overview() -> None:
    fake = _FakeLaravelClient()
    result = await tools_module.dispatch_tool(
        fake,
        "bmssiteops_site_overview",
        {"site_id": 7},  # type: ignore[arg-type]
    )
    assert result["summary"]["site_id"] == 7


@pytest.mark.asyncio
async def test_dispatch_tool_routes_ask_with_optional_site_id() -> None:
    fake = _FakeLaravelClient()
    await tools_module.dispatch_tool(
        fake,
        "bmssiteops_ask",
        {"question": "q?"},  # type: ignore[arg-type]
    )
    assert fake.calls[0] == ("ask", (), {"question": "q?", "site_id": None})

    await tools_module.dispatch_tool(
        fake,
        "bmssiteops_ask",
        {"question": "q?", "site_id": 5},  # type: ignore[arg-type]
    )
    assert fake.calls[1] == ("ask", (), {"question": "q?", "site_id": 5})


@pytest.mark.asyncio
async def test_dispatch_tool_routes_create_script() -> None:
    fake = _FakeLaravelClient()
    await tools_module.dispatch_tool(
        fake,  # type: ignore[arg-type]
        "bmssiteops_create_script",
        {"title": "T", "prompt": "P", "language": "python"},
    )
    assert fake.calls[0] == (
        "create_script",
        (),
        {"title": "T", "prompt": "P", "language": "python"},
    )


@pytest.mark.asyncio
async def test_dispatch_tool_raises_on_unknown_tool() -> None:
    fake = _FakeLaravelClient()
    with pytest.raises(ValueError, match="Unknown MCP tool"):
        await tools_module.dispatch_tool(fake, "bmssiteops_nope", {})  # type: ignore[arg-type]
