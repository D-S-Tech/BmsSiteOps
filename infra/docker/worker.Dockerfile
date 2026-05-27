# =============================================================================
# BmsSiteOps Worker — Python 3.12 / FastAPI / uv
# =============================================================================
# Image is consumed by infra/compose/docker-compose.{dev,prod}.yml as service
# `worker`. Hosts collectors (TRMM, Niagara, BACnet) and AI task handlers.
# Caddy reverse-proxies ops-mcp.* to this service on port 8000.
#
# Multi-stage build:
#   1) base     — system packages + uv installed
#   2) builder  — install Python deps into /opt/venv with uv
#   3) runtime  — slim image + just venv + source + non-root user
#
# Development uses bind-mounted source + uvicorn --reload — see compose dev file.
# =============================================================================

ARG PYTHON_VERSION=3.12

# -----------------------------------------------------------------------------
# Stage 1 — base (uv installed, system deps for compiled wheels)
# -----------------------------------------------------------------------------
FROM python:${PYTHON_VERSION}-slim-bookworm AS base

ENV PYTHONUNBUFFERED=1 \
    PYTHONDONTWRITEBYTECODE=1 \
    PIP_NO_CACHE_DIR=1 \
    PIP_DISABLE_PIP_VERSION_CHECK=1

# System packages — libpq for asyncpg, build-essential for compiled wheels
RUN apt-get update \
    && apt-get install -y --no-install-recommends \
        ca-certificates \
        curl \
        libpq5 \
    && rm -rf /var/lib/apt/lists/*

# uv — modern Python package manager (10-100x faster than pip)
COPY --from=ghcr.io/astral-sh/uv:0.5 /uv /usr/local/bin/uv

# -----------------------------------------------------------------------------
# Stage 2 — builder (install Python deps with build tooling)
# -----------------------------------------------------------------------------
FROM base AS builder

RUN apt-get update \
    && apt-get install -y --no-install-recommends \
        build-essential \
        libpq-dev \
    && rm -rf /var/lib/apt/lists/*

WORKDIR /app

# uv creates the venv at /opt/venv to keep it outside the bind-mount path
ENV UV_PROJECT_ENVIRONMENT=/opt/venv

# Copy only files needed for dependency resolution to maximize layer cache
COPY apps/worker/pyproject.toml apps/worker/uv.lock* ./

RUN --mount=type=cache,target=/root/.cache/uv \
    uv sync --frozen --no-install-project --no-dev

# Now copy source and install the project itself (gets its own layer)
COPY apps/worker/ .

RUN --mount=type=cache,target=/root/.cache/uv \
    uv sync --frozen --no-dev

# -----------------------------------------------------------------------------
# Stage 3 — runtime (slim final image)
# -----------------------------------------------------------------------------
FROM base AS runtime

# Non-root user
RUN groupadd -r -g 1000 app && useradd -r -u 1000 -g app app

WORKDIR /app

# Copy venv + source from builder
COPY --from=builder --chown=app:app /opt/venv /opt/venv
COPY --from=builder --chown=app:app /app /app

# Activate the venv
ENV PATH="/opt/venv/bin:$PATH" \
    VIRTUAL_ENV=/opt/venv

USER app

EXPOSE 8000

# Healthcheck — FastAPI /health endpoint (Sprint 0 Day 4 will add the route)
HEALTHCHECK --interval=30s --timeout=5s --start-period=15s --retries=3 \
    CMD curl --fail --silent http://127.0.0.1:8000/health || exit 1

# Production command — no --reload, multiple workers via gunicorn or uvicorn
CMD ["uvicorn", "app.main:app", "--host", "0.0.0.0", "--port", "8000", "--workers", "2"]
