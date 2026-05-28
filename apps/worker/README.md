# BmsSiteOps Worker — Python async backend

The async worker hosts data-source collectors (Tactical RMM, Tridium Niagara via Fox, BACnet/IP) and AI task handlers. It is a thin FastAPI app, called by the Laravel API over HTTP for internal RPCs and by external clients for the MCP endpoint.

## Stack

- Python 3.12
- FastAPI + uvicorn (HTTP)
- asyncpg (PostgreSQL)
- redis-py (pub/sub, cache)
- httpx (outbound HTTP)
- pydantic + pydantic-settings (config + DTOs)
- anthropic SDK (AI handlers)
- structlog (structured logging)
- pytest + pytest-asyncio (tests)
- ruff (lint + format)
- mypy (strict type checking)
- **uv** for dependency resolution and installation

## Running locally

Via Docker Compose from the repo root:

```bash
make dev-up-all          # brings up everything including worker
make sh-worker           # shell into the worker container
make logs-worker         # tail logs
make worker-test         # pytest in the container
make worker-lint         # ruff + mypy in the container
```

For ad-hoc local dev outside Docker:

```bash
cd apps/worker
uv sync                  # creates .venv, installs all deps
uv run uvicorn app.main:app --reload --host 0.0.0.0 --port 8000
```

The first `uv sync` reads `pyproject.toml`, resolves to `uv.lock`, and installs everything into `.venv/`. Subsequent runs are deterministic from the lockfile.

## Layout

```
apps/worker/
├── pyproject.toml                   project metadata + tool config (ruff, mypy, pytest)
├── uv.lock                          pinned dependency tree
├── app/
│   ├── __init__.py                  package marker, __version__
│   ├── main.py                      FastAPI app + lifespan
│   ├── config.py                    pydantic-settings (env vars)
│   ├── runner.py                    SyncRunner — orchestrates collector -> ingest
│   ├── collectors/
│   │   ├── __init__.py              public re-exports
│   │   ├── base.py                  Collector ABC + CollectorConfig + CollectorEvent
│   │   ├── trmm.py                  Tactical RMM collector (live)
│   │   ├── niagara.py               Niagara collector — oBIX (live) + Fox (experimental)
│   │   └── bacnet.py                BACnet/IP collector — bacpypes3 (request-building tested)
│   ├── clients/                     HTTP clients (TRMM, oBIX, internal ingest + brief)
│   ├── ai/                          LLM seam — LiteLLM client + Site Brief generator
│   └── remediation/                 Worker-side remediation seam
│       ├── __init__.py              public re-exports
│       ├── base.py                  RemediationAction/Result + Transport ABC + Fake
│       ├── dispatcher.py            RemediationDispatcher — routes kind -> transport
│       └── trmm.py                  TRMM transport (restart_trmm_agent; respx-tested)
└── tests/                           pytest suite
```

## Adding a new collector

1. Add a new value to `CollectorKind` in `app/collectors/base.py`.
2. Add a matching migration on the Laravel side that extends the `sources.kind` enum.
3. Create `app/collectors/<kind>.py` with a subclass of `Collector`.
4. Re-export it from `app/collectors/__init__.py`.
5. Parameterize `test_collectors.py` with the new subclass.
6. Implement `discover()` and `poll()` against the real protocol.

## Tests

```bash
uv run pytest                     # full suite
uv run pytest -x                  # stop on first failure
uv run pytest -m "not integration"  # skip integration tests
```

Tests marked `@pytest.mark.integration` require external services (postgres, redis) and run only in CI's integration stage (Sprint 1+).

## Lint + type check

```bash
uv run ruff check .               # lint
uv run ruff format .              # format (in place)
uv run mypy app                   # strict type check on the app/ package
```

`pyproject.toml` configures ruff to enforce: pycodestyle, pyflakes, isort, bugbear, comprehensions, pyupgrade, simplify, ruff-specific rules. mypy runs in strict mode.

## Production

The worker image is built from `infra/docker/worker.Dockerfile` (multi-stage, uv-based, non-root user). In production it runs `uvicorn app.main:app --workers 2`. Caddy reverse-proxies `ops-mcp.bmssiteops.com` to this service.
