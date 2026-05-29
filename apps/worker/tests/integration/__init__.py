"""Integration test harness — only runs when LIVE_TESTS=1.

Lives next to the unit tests so it shares the conftest + dependencies, but
every test in this directory is decorated with @pytest.mark.live which
makes it skip by default. The CI pytest invocation runs `pytest -m "not live"`
so CI never tries to reach a real LiteLLM proxy. Local validation runs:

    cd apps/worker
    LIVE_TESTS=1 \\
    LITELLM_BASE_URL=http://10.0.0.42:4000 \\
    LITELLM_MASTER_KEY=<the proxy key> \\
    uv run pytest tests/integration -v

Or via the Makefile shortcut:

    make worker-test-integration

These tests are the operator-side validation that every transport seam built
across Sprints 4, 6, and 7.2 actually round-trips real bytes against a real
proxy. The unit suite proves the request shape is right; this suite proves
the wire transit works end to end.
"""
