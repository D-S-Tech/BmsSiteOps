# BmsSiteOps — Architecture

This document describes the architecture of BmsSiteOps, the rationale for each major choice, and the boundaries between components. For the chronological record of how each decision was reached, see [`adr/`](adr/).

## 1. System overview

BmsSiteOps is a multi-tenant platform that ingests telemetry from heterogeneous data sources at customer sites, normalizes it into a unified data model, and exposes it through web UI, admin console, and an MCP endpoint. An AI layer routes between hosted models (Claude) and local models (Qwen 2.5 Coder via Ollama) for reasoning over the ingested data.

A **site** is the fundamental unit of operation — typically one commercial building or campus. A site has one or more **sources** (a TRMM instance, a Niagara supervisor, a BACnet network), and each source produces **devices**, **events**, and **metrics** that all live under the site.

## 2. Boundaries and ownership

### 2.1 Data sources (external, untrusted)

These are systems BmsSiteOps reads from but does not own:

- **Tactical RMM** — open-source RMM platform. We use its REST API (`/api/v3/`) and webhook capability.
- **Tridium Niagara** — proprietary BAS platform from Honeywell/Tridium. We use the Fox protocol (TCP, default port 1911 / TLS 4911) via a Python client implementation derived from the public Fox protocol specification.
- **BACnet/IP** — ASHRAE standard 135 protocol. We use `bacpypes3` for discovery and polling on UDP/47808.

### 2.2 Platform (internal, trusted)

Everything inside the `bmssiteops` Docker Compose stack:

- **api** — Laravel 11 application providing the REST API, Filament admin panel, and Horizon queue dashboard
- **worker** — Python FastAPI service hosting collectors and AI task handlers
- **postgres** — PostgreSQL 16, primary datastore
- **redis** — Redis 7, used for cache, queues (Laravel Horizon), pub/sub (cross-service signals), and rate limiting
- **meilisearch** — Meilisearch for full-text search across sites, devices, events, and documents
- **caddy** — Caddy 2 as reverse proxy with automatic HTTPS

### 2.3 Interfaces (external, semi-trusted)

- **SvelteKit web UI** — primary operator interface, served at `https://ops.bmssiteops.com`
- **Filament admin** — internal-only admin panel at `/admin`
- **MCP endpoint** — Model Context Protocol server at `https://ops-mcp.bmssiteops.com/sse` for AI agent integration

## 3. Multi-tenancy

BmsSiteOps uses **row-level multi-tenancy** with a `tenant_id` column on every tenant-scoped table and a Laravel global scope (`TenantScope`) that automatically filters all queries.

Rationale and trade-offs are captured in [ADR 0002](adr/0002-multi-tenancy-row-level.md). Briefly: schema-per-tenant gives stronger isolation but operational pain at our scale; row-level with rigorous testing is the right starting point.

The first tenant is BMCE itself. Each commercial customer (when commercialization begins) becomes a separate tenant.

## 4. Data sources and the collector pattern

All data sources are reached through a common `Collector` abstraction (Python) that implements:

```python
class Collector(ABC):
    async def discover(self) -> list[Device]: ...
    async def poll(self) -> AsyncIterator[Event | Metric]: ...
    async def subscribe(self, callback: Callable) -> None: ...  # optional
```

A concrete collector (e.g., `TrmmCollector`, `NiagaraCollector`, `BacnetCollector`) is configured per `source` row in the database. The worker process orchestrates collector lifecycle, schedules polling, and pushes normalized events back to the Laravel API via internal HTTP plus Redis pub/sub for real-time signal.

Adding a new vendor (Modbus, M-Bus, KNX) is a matter of writing a new `Collector` subclass — the data model and downstream pipeline do not change.

## 5. Unified device registry

The `devices` table is the system's most important design decision. A JACE controller, a Windows file server, a BACnet VAV box, and an SNMP-managed switch are all rows in the same table, distinguished by a `kind` enum and a polymorphic `attributes` JSON column. This is what makes the AI layer meaningful — when it reasons about a site, it sees one fabric, not silos.

## 6. AI layer

All AI calls route through a **LiteLLM proxy** that exposes a single OpenAI-compatible endpoint. The proxy handles:

- Provider routing (Claude vs Qwen vs others)
- Cost tracking per call and per tenant
- Rate limiting per tenant
- Fallback chain (if Claude is down, route to Qwen)

The AI worker holds the prompt templates, retrieval logic, and tool-use scaffolding. The Laravel API never talks directly to Anthropic or Ollama — only through LiteLLM.

### 6.1 Routing policy

- **Claude Sonnet/Opus** — customer-facing chat, complex triage, anything where output quality matters most
- **Qwen 2.5 Coder 32B (local)** — high-volume batch jobs: log parsing, draft report generation, script outline, structured data extraction

### 6.2 Retrieval

Site-scoped RAG uses Qdrant for embeddings. Documents (submittals, contracts, drawings, SOOs) are embedded once at upload. Telemetry is embedded in rolling windows for "what happened recently at this site" queries.

## 7. MCP endpoint

The platform exposes its own MCP server at `https://ops-mcp.bmssiteops.com/sse`. This lets the BMCE team query and operate the platform from any MCP-aware client (Claude.ai, Claude Code, custom agents). It is also a competitive differentiator — other RMM platforms have not yet shipped first-party MCP servers.

Authentication is per-user token. Multi-tenancy is enforced at the MCP layer — a tenant token can only see and operate on its own data.

## 8. Real-time updates

**Laravel Reverb** (WebSockets) handles real-time push to the SvelteKit UI. Events flow: collector → worker → Redis pub/sub → Laravel job → broadcast → SvelteKit. The UI subscribes per-site to avoid noisy cross-tenant traffic.

## 9. Deployment topology (Sprint 0)

A single LXC container on Virtualizor Hypervisor 1, running the full Docker Compose stack behind Caddy with automatic HTTPS. This is sufficient for internal use (BMCE) and pilot customers. Horizontal scaling and multi-region come later when warranted.

## 10. Security baseline

This is a **public repository**. The following rules are non-negotiable:

1. No secrets in code. All credentials in `.env` (gitignored). `.env.example` is the only env file in the repo.
2. No customer data in seeders, fixtures, tests, or documentation. Use synthetic data only.
3. All inbound credentials (TRMM API token, Niagara station password, BACnet write access) are stored as Laravel `encrypted` casts in the database.
4. All inter-service traffic stays on the Docker bridge network; nothing exposed outside Caddy.
5. Caddy enforces TLS 1.3 minimum. HSTS preload.
6. Audit log table records every state-changing API call with actor, tenant, target, before/after JSON.

See [ADR 0004](adr/0004-public-repo-security.md) for the full security posture.

## 11. Non-goals (for now)

- **Endpoint agent of our own** — we use TRMM's agent. We are not building an alternative.
- **Real-time control loop (closed-loop BMS)** — BmsSiteOps observes and recommends; it does not write setpoints. (Possible future capability, but explicitly scoped out for v1.)
- **Mobile app** — the SvelteKit UI is responsive and PWA-capable; no native app planned.
- **Marketplace/plugin system** — collectors are part of the codebase, not user-installable extensions.

## 12. Open architectural questions

These are deliberately deferred and tracked as future ADRs:

- **TimescaleDB migration trigger** — at what metrics volume do we switch from plain Postgres to TimescaleDB? (Sprint 3 review.)
- **Multi-region** — if a customer in EU demands data residency, what's the minimum viable split? (Out of scope until first EU prospect.)
- **Niagara write path** — should we ever issue commands back through Fox? (Held until v2.)
- **Customer SSO** — SAML / OIDC for commercial customers. (Held until first customer asks.)
