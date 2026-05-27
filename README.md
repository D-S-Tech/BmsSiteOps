# BmsSiteOps

BMS-aware operations platform for HVAC/MEP contractors. Combines IT endpoint monitoring (via Tactical RMM) with building automation telemetry (Tridium Niagara, BACnet/IP) into a single per-site operational view, with an integrated AI layer for triage, reporting, and script authoring.

**Status:** Pre-alpha · Sprint 0 (scaffolding) · Not yet ready for use.

---

## Why this exists

Most RMM platforms treat servers and workstations as the world. Most building management systems treat HVAC and controls as the world. For an MEP/BMS contractor servicing commercial buildings, neither half is enough — when a tenant complains the office is hot, the answer might live in a tripped RTU fan, a stuck VAV damper, a SQL backup that hung the supervisor, or a Windows update that broke the Niagara station service.

BmsSiteOps unifies both halves under one site, normalizes the events, and lets an AI layer reason across them.

## Architecture (overview)

```
External data sources
   TRMM API · Niagara Fox · BACnet/IP
        |
        v
[ BmsSiteOps platform ]
   Python collectors -> Laravel API -> Postgres + Redis
                              |
                              v
                       LiteLLM router
                       /            \
                  Claude API     Qwen 2.5 (local)
        |
        v
External interfaces
   Filament admin · SvelteKit UI · MCP endpoint
```

See [`docs/ARCHITECTURE.md`](docs/ARCHITECTURE.md) for the full architecture document, and [`docs/adr/`](docs/adr/) for the architecture decision records explaining why each major choice was made.

## Tech stack

| Layer       | Technology                                    |
|-------------|-----------------------------------------------|
| Backend     | Laravel 11 + Filament 3 + Sanctum + Horizon   |
| Frontend    | SvelteKit 5 (Svelte 5 runes) + TypeScript     |
| Worker      | Python 3.12 + FastAPI + uvicorn               |
| Database    | PostgreSQL 16 (TimescaleDB later)             |
| Cache/Queue | Redis 7                                       |
| Search      | Meilisearch                                   |
| AI router   | LiteLLM                                       |
| Models      | Claude (Anthropic) + Qwen 2.5 Coder (local)   |
| Reverse proxy | Caddy 2 (automatic HTTPS)                   |
| Container   | Docker + Docker Compose                       |

## Repository layout

```
BmsSiteOps/
├── apps/
│   ├── api/      Laravel 11 backend + Filament admin
│   ├── web/      SvelteKit 5 frontend
│   └── worker/   Python FastAPI service (collectors + AI tasks)
├── infra/
│   ├── docker/   Dockerfiles for each app
│   ├── compose/  docker-compose stacks (dev, prod)
│   └── caddy/    Caddyfile templates
├── docs/
│   ├── ARCHITECTURE.md
│   └── adr/      Architecture Decision Records
└── .github/workflows/
```

## Local development

> Detailed setup instructions land in Sprint 0 Day 2 once the Docker Compose stack is in place. For now this is a scaffold-only repository.

The intended developer workflow:

```bash
git clone https://github.com/D-S-Tech/BmsSiteOps.git
cd BmsSiteOps
cp .env.example .env
make dev-up
```

## Data sources (planned)

- **Tactical RMM** — Windows/Linux/macOS endpoints, checks, alerts, scripts
- **Tridium Niagara** — JACE controllers, station health, BACnetNetwork alarms, history extension data
- **BACnet/IP** — direct DDC controller polling for retrofits without a supervisor
- **SNMP** (future) — network gear health
- **Modbus TCP** (future) — meters, generators, BMS retrofits

## AI use cases (Sprint 4–7)

1. **Daily site brief** — AI-generated summary of last 24h across IT + BMS per site
2. **Alert triage** — classify incoming alerts, auto-remediate known issues
3. **Script authoring** — describe what you want, AI generates TRMM checks/scripts
4. **Site Q&A** — chat interface backed by RAG over site telemetry + documents

## License

[AGPL-3.0](LICENSE). If you fork BmsSiteOps and run a hosted service from it, you must publish your modifications under the same license.

## Maintainer

Denny Pjevalica — [Bold Mechanical & Controls Enterprise, Inc.](https://boldmech.com) / D&S Tech LLC.

---

> This is an internal-first tool. Issues and pull requests from outside contributors will be acknowledged but not necessarily merged while the project is in early development.
