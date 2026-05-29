"""Worker LaravelClient X-MCP-Tool header tests (Sprint 9.2).

Proves that every MCP tool method on LaravelClient stamps the outbound
request with the `X-MCP-Tool` header naming itself. The Laravel-side
LogMcpToolCalls middleware reads that header to populate the
mcp_audit_entries table.
"""

from __future__ import annotations

import httpx
import pytest
import respx

from app.mcp.laravel_client import LaravelClient


@pytest.fixture()
def base() -> str:
    return "https://api.example.com"


@pytest.mark.asyncio
@respx.mock
async def test_list_sites_sends_x_mcp_tool_header(base: str) -> None:
    route = respx.get(f"{base}/api/v1/sites").mock(
        return_value=httpx.Response(200, json={"data": []})
    )

    async with httpx.AsyncClient(base_url=base) as inner:
        await LaravelClient(base, "test-token", client=inner).list_sites()

    assert route.called
    request = route.calls[0].request
    assert request.headers["X-MCP-Tool"] == "bmssiteops_list_sites"
    assert request.headers["Authorization"] == "Bearer test-token"


@pytest.mark.asyncio
@respx.mock
async def test_site_overview_sends_x_mcp_tool_header_on_both_inner_calls(
    base: str,
) -> None:
    summary_route = respx.get(f"{base}/api/v1/sites/7/summary").mock(
        return_value=httpx.Response(200, json={"data": {"site_id": 7}})
    )
    briefs_route = respx.get(f"{base}/api/v1/sites/7/briefs").mock(
        return_value=httpx.Response(200, json={"data": []})
    )

    async with httpx.AsyncClient(base_url=base) as inner:
        await LaravelClient(base, "test-token", client=inner).site_overview(7)

    assert summary_route.called
    assert briefs_route.called
    # The site_overview helper makes two inner calls; both must be tagged
    # so the audit trail attributes each row correctly.
    assert summary_route.calls[0].request.headers["X-MCP-Tool"] == "bmssiteops_site_overview"
    assert briefs_route.calls[0].request.headers["X-MCP-Tool"] == "bmssiteops_site_overview"


@pytest.mark.asyncio
@respx.mock
async def test_ask_sends_x_mcp_tool_header(base: str) -> None:
    route = respx.post(f"{base}/api/v1/qa").mock(
        return_value=httpx.Response(200, json={"data": {"answer": "..."}})
    )

    async with httpx.AsyncClient(base_url=base) as inner:
        await LaravelClient(base, "test-token", client=inner).ask("q?")

    assert route.called
    assert route.calls[0].request.headers["X-MCP-Tool"] == "bmssiteops_ask"


@pytest.mark.asyncio
@respx.mock
async def test_create_script_sends_x_mcp_tool_header(base: str) -> None:
    route = respx.post(f"{base}/api/v1/scripts").mock(
        return_value=httpx.Response(200, json={"data": {"id": 1}})
    )

    async with httpx.AsyncClient(base_url=base) as inner:
        await LaravelClient(base, "test-token", client=inner).create_script("T", "P", "python")

    assert route.called
    assert route.calls[0].request.headers["X-MCP-Tool"] == "bmssiteops_create_script"


@pytest.mark.asyncio
@respx.mock
async def test_headers_helper_omits_x_mcp_tool_when_not_provided(
    base: str,
) -> None:
    """Defensive: the internal _headers() helper must NOT inject the tool
    header by default. Only the public tool methods pass it. A future
    non-MCP use of LaravelClient (e.g. from a queue worker) must remain
    unaudited.
    """
    client = LaravelClient(base, "test-token")
    headers = client._headers()  # type: ignore[reportPrivateUsage]
    assert "X-MCP-Tool" not in headers
    assert headers["Authorization"] == "Bearer test-token"
