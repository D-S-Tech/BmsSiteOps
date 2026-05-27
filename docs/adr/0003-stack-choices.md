# ADR 0003 — Stack choices

**Status:** Accepted
**Date:** 2026-05-27

## Context

BmsSiteOps requires choices for: backend web framework and admin panel, frontend framework, async data-ingestion worker, primary datastore, cache/queue, search, AI routing, reverse proxy, and container runtime.

The maintainer (Denny Pjevalica) has substantial production experience with Laravel and SvelteKit; less with the alternatives. The product is operations-oriented, not consumer-scale traffic — a few hundred concurrent operator sessions and a high volume of background collector work, not a million-RPS public endpoint.

## Decision

| Layer            | Choice                                       |
|------------------|----------------------------------------------|
| Backend          | Laravel 13 (PHP 8.3)                         |
| Admin panel      | Filament 5                                   |
| Auth             | Laravel Sanctum (token + session)            |
| Queues           | Laravel Horizon on Redis                     |
| Testing          | PHPUnit 12 + Laravel Pao (Laravel's default) |
| Frontend         | SvelteKit 5 + TypeScript                     |
| Worker           | Python 3.12 + FastAPI                        |
| Datastore        | PostgreSQL 16 (TimescaleDB later — see future ADR) |
| Cache / queue / pubsub | Redis 7                                |
| Search           | Meilisearch                                  |
| AI router        | LiteLLM                                      |
| AI models        | Claude (hosted) + Qwen 2.5 Coder (local via Ollama) |
| Reverse proxy    | Caddy 2                                      |
| Runtime          | Docker + Docker Compose                      |

(Initial scaffolding in Sprint 0 Day 3 landed on Laravel 13.12 and Filament 5.6, which were the current stable releases. The version numbers in this table track the major versions; minor/patch updates do not require a new ADR.)

## Consequences

**Backend (Laravel 13 + Filament 5)**

The maintainer already operates Laravel applications in production (MEPSub, BMCE Submittal Builder, NovelPress, Balkan Radio). Filament 5 is a production-grade admin panel built on Laravel, capable of replacing 80% of the CRUD work that would otherwise consume frontend engineering hours. Sanctum's token mode is a well-understood pairing with SPA frontends. Horizon gives a credible queue dashboard out of the box.

Laravel 13's attribute-based model configuration (`#[Fillable]`, `#[Hidden]`) replaces the older property-based syntax, but is otherwise a transparent evolution from 11/12.

**Testing (PHPUnit + Pao)**

Laravel 13 ships with `laravel/pao` as the default agent-optimized testing tool. Pao wraps PHPUnit/Pest output so that AI agents reading test results get structured, parseable information. Pest 4 currently conflicts with Pao in the Laravel 13 release, so the project uses PHPUnit 12 directly (which Pao supports). If a future Pest version regains compatibility with Pao, switching is a one-package swap.

**Frontend (SvelteKit 5)**

Svelte 5 runes (`$state`, `$derived`, `$effect`, `$props`) produce a more legible reactive model than the React/Next.js equivalent for this kind of operations dashboard work. SvelteKit's file-based routing and load-function architecture aligns with how the maintainer thinks about pages. The bundle is small, the runtime is small, the conceptual surface is small.

**Worker (Python 3.12 + FastAPI)**

The data sources are dominated by libraries with the best Python support: `bacpypes3` for BACnet, mature Fox protocol clients in Python, the entire AI/ML ecosystem. FastAPI is the right shape for an internal-only service that the Laravel API talks to over HTTP plus Redis pub/sub for asynchronous signals. Putting collectors in PHP would be a fight; putting AI orchestration in PHP would be a bigger fight.

**Datastore (PostgreSQL 16, plain)**

PostgreSQL is non-negotiable for the relational data. TimescaleDB is the right destination for metrics once volume justifies the operational overhead, but at Sprint 0 there are no metrics to time-series — starting plain and migrating later (Sprint 3 review) avoids paying for complexity that isn't yet needed.

**Search (Meilisearch)**

The maintainer has Meilisearch already deployed in MEPSub. Laravel Scout has a first-party driver. Adding Meilisearch costs nothing in mental load.

**AI router (LiteLLM)**

A single OpenAI-compatible endpoint that fronts Anthropic, Ollama, and any future provider gives per-tenant cost accounting, rate limiting, and provider fallback for free. Building this in-house would consume a sprint by itself.

**Reverse proxy (Caddy 2)**

Automatic HTTPS via Let's Encrypt is non-negotiable for production. The maintainer's existing infrastructure already uses Caddy. The Caddyfile is short and readable in a way nginx is not.

**Runtime (Docker Compose)**

The deployment target is a single LXC container on Virtualizor for the first year. Docker Compose is the right tool at that scale. Kubernetes is a re-platform when (and only when) horizontal scaling, multi-region, or operational complexity demands it.

## Alternatives considered

- **NestJS + Next.js** — Full TypeScript stack would simplify cross-cutting concerns, but the worker work is dominated by Python libraries and the maintainer's Laravel productivity advantage is too large to discard.
- **Django + Vue** — Reasonable but the Filament 5 admin panel has no equivalent in Django, and replicating it would be significant work.
- **All-Python (FastAPI + HTMX or similar)** — Tempting for stack homogeneity but loses Laravel's mature admin tooling.
- **MariaDB / MySQL** instead of Postgres — PostgreSQL's JSON support, partial indexes, and TimescaleDB upgrade path are decisive for time-series data.
- **Pest** as the test runner — Laravel 13's default `laravel/pao` package currently conflicts with Pest 4.x. We use PHPUnit 12 (which Pao supports) until that is resolved.

## See also

- [ADR 0001 — Monorepo](./0001-monorepo.md)
- [ADR 0002 — Multi-tenancy: row-level](./0002-multi-tenancy-row-level.md)
- [ADR 0006 — Containerization patterns](./0006-containerization-patterns.md)
