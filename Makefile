# BmsSiteOps — top-level Makefile
# Wraps the docker-compose lifecycle so day-to-day dev fits in muscle memory.

COMPOSE_DEV  := docker compose -f infra/compose/docker-compose.dev.yml
COMPOSE_PROD := docker compose -f infra/compose/docker-compose.prod.yml

.PHONY: help
help:
	@echo "BmsSiteOps — available targets"
	@echo ""
	@echo "  Local development"
	@echo "    make dev-up        Start the dev stack (postgres, redis, api, web, worker, caddy, meilisearch)"
	@echo "    make dev-down      Stop the dev stack"
	@echo "    make dev-restart   Restart the dev stack"
	@echo "    make dev-rebuild   Rebuild all images (after Dockerfile changes)"
	@echo "    make dev-clean     Tear down everything including volumes (DESTROYS LOCAL DATA)"
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
	@echo "    make prod-deploy   Pull latest images and reload"

# --- Dev lifecycle ---
.PHONY: dev-up dev-down dev-restart dev-rebuild dev-clean logs logs-api logs-worker logs-web
dev-up:
	$(COMPOSE_DEV) up -d

dev-down:
	$(COMPOSE_DEV) down

dev-restart:
	$(COMPOSE_DEV) restart

dev-rebuild:
	$(COMPOSE_DEV) build --no-cache
	$(COMPOSE_DEV) up -d

dev-clean:
	$(COMPOSE_DEV) down -v --remove-orphans

logs:
	$(COMPOSE_DEV) logs -f --tail=200

logs-api:
	$(COMPOSE_DEV) logs -f --tail=200 api

logs-worker:
	$(COMPOSE_DEV) logs -f --tail=200 worker

logs-web:
	$(COMPOSE_DEV) logs -f --tail=200 web

# --- Shells ---
.PHONY: sh-api sh-worker sh-web sh-db
sh-api:
	$(COMPOSE_DEV) exec api bash

sh-worker:
	$(COMPOSE_DEV) exec worker bash

sh-web:
	$(COMPOSE_DEV) exec web sh

sh-db:
	$(COMPOSE_DEV) exec postgres psql -U bmssiteops -d bmssiteops

# --- Laravel targets ---
.PHONY: api-migrate api-seed api-fresh api-test api-pint
api-migrate:
	$(COMPOSE_DEV) exec api php artisan migrate

api-seed:
	$(COMPOSE_DEV) exec api php artisan db:seed

api-fresh:
	$(COMPOSE_DEV) exec api php artisan migrate:fresh --seed

api-test:
	$(COMPOSE_DEV) exec api ./vendor/bin/pest

api-pint:
	$(COMPOSE_DEV) exec api ./vendor/bin/pint

# --- SvelteKit targets ---
.PHONY: web-install web-check web-test
web-install:
	$(COMPOSE_DEV) exec web npm install

web-check:
	$(COMPOSE_DEV) exec web npm run check

web-test:
	$(COMPOSE_DEV) exec web npm run test

# --- Python worker targets ---
.PHONY: worker-test worker-lint
worker-test:
	$(COMPOSE_DEV) exec worker pytest

worker-lint:
	$(COMPOSE_DEV) exec worker ruff check .
	$(COMPOSE_DEV) exec worker mypy app

# --- Production ---
.PHONY: prod-up prod-down prod-deploy
prod-up:
	$(COMPOSE_PROD) up -d

prod-down:
	$(COMPOSE_PROD) down

prod-deploy:
	git pull --ff-only
	$(COMPOSE_PROD) pull
	$(COMPOSE_PROD) up -d --remove-orphans
