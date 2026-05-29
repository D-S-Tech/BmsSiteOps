# BmsSiteOps — top-level Makefile
# Wraps the docker-compose lifecycle so day-to-day dev fits in muscle memory.

COMPOSE_DEV       := docker compose -f infra/compose/docker-compose.dev.yml
COMPOSE_DEV_APPS  := docker compose -f infra/compose/docker-compose.dev.yml --profile apps
COMPOSE_PROD      := docker compose -f infra/compose/docker-compose.prod.yml

.PHONY: help
help:
	@echo "BmsSiteOps — available targets"
	@echo ""
	@echo "  Local development"
	@echo "    make dev-up        Start infra only (postgres, redis, meilisearch)"
	@echo "    make dev-up-all    Start infra + apps (api, web, worker, caddy)"
	@echo "    make dev-down      Stop everything (including app profile services)"
	@echo "    make dev-restart   Restart the running stack"
	@echo "    make dev-rebuild   Rebuild all images (after Dockerfile changes)"
	@echo "    make dev-clean     Tear down everything including volumes (DESTROYS LOCAL DATA)"
	@echo "    make dev-ps        Show the dev stack status"
	@echo "    make logs          Tail all dev logs"
	@echo "    make logs-api      Tail Laravel api logs"
	@echo "    make logs-worker   Tail Python worker logs"
	@echo "    make logs-web      Tail SvelteKit logs"
	@echo ""
	@echo "  App shells"
	@echo "    make sh-api        Open a shell in the api container"
	@echo "    make sh-worker     Open a shell in the worker container"
	@echo "    make sh-web        Open a shell in the web container"
	@echo "    make sh-db         Open a psql shell"
	@echo "    make sh-redis      Open a redis-cli shell"
	@echo ""
	@echo "  Laravel"
	@echo "    make api-migrate   Run database migrations"
	@echo "    make api-seed      Run database seeders"
	@echo "    make api-fresh     Drop + recreate + migrate + seed (DESTRUCTIVE)"
	@echo "    make api-test      Run Pest test suite"
	@echo "    make api-pint      Run Laravel Pint formatter"
	@echo ""
	@echo "  SvelteKit"
	@echo "    make web-install   Install npm dependencies"
	@echo "    make web-check     Run svelte-check"
	@echo "    make web-test      Run Vitest"
	@echo ""
	@echo "  Python worker"
	@echo "    make worker-test   Run pytest"
	@echo "    make worker-lint   Run ruff + mypy"
	@echo ""
	@echo "  Production"
	@echo "    make prod-up       Bring up the production stack"
	@echo "    make prod-down     Stop the production stack"
	@echo "    make prod-ps       Show the prod stack status"
	@echo "    make prod-deploy   Pull latest images and reload"

# --- Dev lifecycle ---
.PHONY: dev-up dev-up-all dev-down dev-restart dev-rebuild dev-clean dev-ps logs logs-api logs-worker logs-web
dev-up:
	$(COMPOSE_DEV) up -d

dev-up-all:
	$(COMPOSE_DEV_APPS) up -d

dev-down:
	$(COMPOSE_DEV_APPS) down

dev-restart:
	$(COMPOSE_DEV_APPS) restart

dev-rebuild:
	$(COMPOSE_DEV_APPS) build --no-cache
	$(COMPOSE_DEV_APPS) up -d

dev-clean:
	$(COMPOSE_DEV_APPS) down -v --remove-orphans

dev-ps:
	$(COMPOSE_DEV_APPS) ps

logs:
	$(COMPOSE_DEV_APPS) logs -f --tail=200

logs-api:
	$(COMPOSE_DEV_APPS) logs -f --tail=200 api

logs-worker:
	$(COMPOSE_DEV_APPS) logs -f --tail=200 worker

logs-web:
	$(COMPOSE_DEV_APPS) logs -f --tail=200 web

# --- Shells ---
.PHONY: sh-api sh-worker sh-web sh-db sh-redis
sh-api:
	$(COMPOSE_DEV_APPS) exec api bash

sh-worker:
	$(COMPOSE_DEV_APPS) exec worker bash

sh-web:
	$(COMPOSE_DEV_APPS) exec web sh

sh-db:
	$(COMPOSE_DEV) exec postgres psql -U bmssiteops -d bmssiteops

sh-redis:
	$(COMPOSE_DEV) exec redis redis-cli

# --- Laravel targets ---
.PHONY: api-migrate api-seed api-fresh api-test api-pint
api-migrate:
	$(COMPOSE_DEV_APPS) exec api php artisan migrate

api-seed:
	$(COMPOSE_DEV_APPS) exec api php artisan db:seed

api-fresh:
	$(COMPOSE_DEV_APPS) exec api php artisan migrate:fresh --seed

api-test:
	$(COMPOSE_DEV_APPS) exec api ./vendor/bin/pest

api-pint:
	$(COMPOSE_DEV_APPS) exec api ./vendor/bin/pint

# --- SvelteKit targets ---
.PHONY: web-install web-check web-test
web-install:
	$(COMPOSE_DEV_APPS) exec web npm install

web-check:
	$(COMPOSE_DEV_APPS) exec web npm run check

web-test:
	$(COMPOSE_DEV_APPS) exec web npm run test

# --- Python worker targets ---
.PHONY: worker-test worker-lint
worker-test:
	$(COMPOSE_DEV_APPS) exec worker uv run pytest

worker-lint:
	$(COMPOSE_DEV_APPS) exec worker uv run ruff check .
	$(COMPOSE_DEV_APPS) exec worker uv run mypy app

# --- Production ---
# -----------------------------------------------------------------------------
# Live integration tests
# -----------------------------------------------------------------------------
# Each side has a separate suite that hits real external services (LiteLLM
# proxy, Ollama, Anthropic, a running worker). Both are gated on LIVE_TESTS=1
# and skipped from the default test runs.
#
# Run them:
#   make worker-test-integration    (worker -> LiteLLM proxy)
#   make api-test-integration       (api -> worker -> LiteLLM)
#
# Both target the *dev* stack — bring it up first with `make dev-up-all`.
# Required env vars are documented in each side's tests/integration/ README.
# -----------------------------------------------------------------------------
.PHONY: worker-test-integration api-test-integration test-integration
worker-test-integration:
	$(COMPOSE_DEV_APPS) exec -e LIVE_TESTS=1 worker uv run pytest tests/integration -m live -v

api-test-integration:
	$(COMPOSE_DEV_APPS) exec -e LIVE_TESTS=1 api php artisan test --testsuite=Integration

test-integration: worker-test-integration api-test-integration

# -----------------------------------------------------------------------------
# Smoke test — end-to-end validator against a running stack
# -----------------------------------------------------------------------------
# Uploads a synthetic document, waits for the worker to embed it, asks a
# question whose answer is in the doc, asserts the response is Ready with
# non-empty answer + citations. Exit code != 0 on any failure.
#
# Use after every prod-deploy and as the acceptance test that the live
# BOLDNJPC AI stack is wired correctly.
#
# Required env (passed through to the script):
#   API_BASE_URL      base URL of the api service
#   API_BEARER_TOKEN  a Sanctum personal access token
#
# Optional: POLL_TIMEOUT_SEC, POLL_INTERVAL_SEC, QUESTION, VERBOSE.
# -----------------------------------------------------------------------------
.PHONY: smoke-test
smoke-test:
	@./infra/scripts/smoke-test.sh

# -----------------------------------------------------------------------------
# MCP smoke test — validates the live MCP SSE handshake + tool round trip
# -----------------------------------------------------------------------------
# Walks: SSE connect -> initialize -> list_tools -> call_tool. Used as the
# acceptance test that the MCP server is wired correctly after a deploy,
# and as the integration validator for Sprint 7.4's MCP work. Run from the
# worker venv so the mcp SDK is available.
#
# Required env: MCP_BASE_URL (e.g. https://ops-mcp.bmssiteops.com)
# Optional env: MCP_TIMEOUT_SEC (default 30)
# -----------------------------------------------------------------------------
.PHONY: mcp-smoke
mcp-smoke:
	@uv run --project apps/worker python infra/scripts/mcp-smoke.py

# -----------------------------------------------------------------------------
# MCP credential generator (Sprint 8.5)
# -----------------------------------------------------------------------------
# Interactive helper that wraps `caddy hash-password` (run via the official
# caddy:2-alpine image, no local caddy install needed). Prompts for a
# password, generates a bcrypt hash, prints the recipe for wiring it into
# .env and mcp-basic-auth.conf.
# -----------------------------------------------------------------------------
.PHONY: mcp-gen-credentials
mcp-gen-credentials:
	@./infra/scripts/mcp-gen-credentials.sh

.PHONY: prod-up prod-down prod-ps prod-deploy
prod-up:
	$(COMPOSE_PROD) up -d

prod-down:
	$(COMPOSE_PROD) down

prod-ps:
	$(COMPOSE_PROD) ps

prod-deploy:
	git pull --ff-only
	$(COMPOSE_PROD) build --pull
	$(COMPOSE_PROD) up -d --remove-orphans
