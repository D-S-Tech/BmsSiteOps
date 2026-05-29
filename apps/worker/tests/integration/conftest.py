"""Pytest configuration for the live integration suite.

Adds a `live` marker and skips every test bearing that marker unless
LIVE_TESTS=1 is in the environment.
"""

from __future__ import annotations

import os

import pytest

LIVE = os.environ.get("LIVE_TESTS") == "1"


def pytest_configure(config: pytest.Config) -> None:
    config.addinivalue_line(
        "markers",
        "live: integration test that hits real external services (LiteLLM, "
        "Ollama, Anthropic, Laravel). Skipped unless LIVE_TESTS=1 is set.",
    )


def pytest_collection_modifyitems(config: pytest.Config, items: list[pytest.Item]) -> None:
    """Skip all @pytest.mark.live items when LIVE_TESTS != '1'."""
    if LIVE:
        return
    skip_live = pytest.mark.skip(reason="LIVE_TESTS=1 not set (integration tests skipped)")
    for item in items:
        if "live" in item.keywords:
            item.add_marker(skip_live)
