# ADR 0006 — Containerization patterns

**Status:** Accepted
**Date:** 2026-05-27

## Context

[ADR 0003](./0003-stack-choices.md) chose Docker + Docker Compose as the runtime. That decision leaves three sub-questions unanswered:

1. **API container shape** — how does PHP get from a Laravel request to a response inside a container? FrankenPHP single-process? nginx + PHP-FPM in two containers? PHP-FPM behind Caddy via FastCGI?
2. **Web container shape** — is the SvelteKit app served via SSR (adapter-node), pre-rendered static (adapter-static), or hybrid (adapter-cloudflare-style)?
3. **Worker Python dependency manager** — pip, poetry, hatch, or uv?

These are independent decisions but they share a deadline (Sprint 0 Day 2 ships the Docker Compose stack), so they are recorded in one ADR.

## Decision

### 3.1 API: Caddy + PHP-FPM via FastCGI

The `api` container runs **only** `php-fpm` listening on port 9000. The `caddy` container holds the entire HTTP frontend, including:

- TLS termination
- HTTP/3 (QUIC) negotiation
- Static asset serving from `apps/api/public/` (bind-mounted read-only into Caddy)
- FastCGI proxying of `.php` requests to `api:9000` via Caddy's `php_fastcgi` directive
- Reverse proxying of all non-API paths to the `web` service

No nginx anywhere in the stack.

### 3.2 Web: SvelteKit with adapter-node (SSR)

The `web` container runs `node build/index.js` produced by `@sveltejs/adapter-node`. Caddy reverse-proxies non-`/api/*` and non-`/admin*` paths to `web:3000`.

### 3.3 Worker: uv

The `worker` container uses `uv` (from Astral) for Python dependency management. `pyproject.toml` defines deps; `uv.lock` pins them; `uv sync --frozen` installs them.

## Consequences

### API: Caddy + PHP-FPM

**Positive**

- One Caddy container handles all HTTP for the whole stack. TLS configuration, security headers, HSTS, HTTP/3 — all in one place.
- PHP-FPM is the most mature, best-understood PHP runtime. No surprise tuning required.
- Static assets served by Caddy (a Go binary) rather than PHP — cheaper, faster.
- Easy to scale: multiple `api` replicas behind one Caddy with FastCGI upstreams.

**Negative**

- The API container does not produce HTTP — it cannot be hit directly during local debugging without going through Caddy. Mitigated: developers use `make sh-api` to drop into the container for CLI work.
- Caddy needs read access to `apps/api/public/`. The bind-mount is read-only and contains no secrets (it is the public web root) — acceptable.

**Alternatives considered**

- **FrankenPHP** — modern, fast, single container, but introduces a less-familiar PHP runtime and pins us to its release cadence. The team's PHP-FPM experience is the deciding factor. Revisit when FrankenPHP reaches v2 stable.
- **nginx + php-fpm in one container with supervisord** — anti-pattern (multiple processes per container). No.
- **nginx in one container + php-fpm in another, both behind Caddy** — three layers of HTTP for what one layer of Caddy handles. Wasteful.

### Web: adapter-node SSR

**Positive**

- Server-side rendering for the dashboard means correct initial page load (no flash of unstyled, no client-side rehydration jank).
- Same runtime in dev and prod — `npm run dev` and `node build/index.js` use the same code paths.
- Server-side data loading via SvelteKit's `+page.server.ts` keeps API tokens off the client.
- Cookies (session, CSRF) work correctly through the SSR layer.

**Negative**

- Requires a Node process running 24/7. Mitigated: the `web` container's footprint is small (~80 MB image, ~50 MB RSS).
- Cannot trivially deploy to static-only hosts. Acceptable — we self-host.

**Alternatives considered**

- **adapter-static** — pre-renders everything to HTML at build. Wrong fit for a dashboard with per-tenant authenticated views.
- **adapter-cloudflare / adapter-vercel** — assumes deployment to that platform's edge. We self-host.

### Worker: uv

**Positive**

- 10–100× faster than pip for install and resolution.
- `uv.lock` produces reproducible installs.
- Drop-in replacement for pip in `pyproject.toml`-based projects.
- Single binary, no system Python dependency.
- The maintainer already uses uv in other projects.

**Negative**

- Still relatively new (released 2024). Some edge cases around build backends. Acceptable; the worker's dependencies are mainstream (FastAPI, asyncpg, bacpypes3, anthropic).

**Alternatives considered**

- **pip + venv** — works, but slow and lockfile story is poor (requirements.txt is not a real lockfile).
- **poetry** — mature, but slower than uv and adds its own packaging idioms.
- **hatch** — focused on packaging, less on dependency management; wrong tool for an application.

## See also

- [ADR 0003 — Stack choices](./0003-stack-choices.md) — the original runtime decision.
- [`infra/docker/`](../../infra/docker/) — the Dockerfiles that implement these choices.
- [`infra/caddy/Caddyfile`](../../infra/caddy/Caddyfile) — the Caddy configuration.
