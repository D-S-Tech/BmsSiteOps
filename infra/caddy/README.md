# Caddy configuration

Caddy is the single HTTP frontend for the entire BmsSiteOps stack: TLS termination, automatic Let's Encrypt certificates, HTTP/3, static asset serving for the Laravel API, and reverse proxying to the SvelteKit and Python services.

## Files

| File | Used by | Purpose |
|---|---|---|
| `Caddyfile` | production (`docker-compose.prod.yml`) | Real domains, automatic HTTPS, HSTS, security headers |
| `Caddyfile.dev` | development (`docker-compose.dev.yml`) | `localhost` + `ops.local`, HTTP only, no certificates |

## How domains are configured

The production `Caddyfile` does not hardcode any hostname. It reads them from environment variables injected by Docker Compose from the host `.env` file:

| Variable | Example | Drives |
|---|---|---|
| `WEB_HOST` | `ops.example.com` | Main web app + API + admin + Horizon |
| `MCP_HOST` | `ops-mcp.example.com` | Model Context Protocol endpoint |
| `ACME_EMAIL` | `ops@example.com` | Let's Encrypt account registration |

To deploy under your own domains, set those three variables in `.env` — no edits to the `Caddyfile` itself are needed.

## Routing model (production)

Within the `WEB_HOST` virtual host, Caddy routes by path:

```
/health                              → api  (FastCGI, public, no auth)
/api/*  /admin*  /horizon*           → api  (FastCGI to PHP-FPM on :9000)
/build/*  /storage/*  /vendor/*      → api  public/ static files (served by Caddy)
/*  (everything else)                → web  (SvelteKit SSR on :3000)
```

The `MCP_HOST` virtual host proxies everything to the Python worker on `:8000`, with SSE-friendly settings (`flush_interval -1`, long read/write timeouts) so Model Context Protocol streaming works.

PHP requests reach PHP-FPM via Caddy's `php_fastcgi` directive. Caddy serves the Laravel `public/` directory directly (bind-mounted read-only into the Caddy container) and hands `.php` execution to the `api` container. See [ADR 0006](../../docs/adr/0006-containerization-patterns.md) for why this shape was chosen over FrankenPHP or nginx + PHP-FPM.

## Certificates

In production, Caddy obtains and renews certificates automatically from Let's Encrypt using the TLS-ALPN-01 / HTTP-01 challenge. For this to succeed:

1. `WEB_HOST` and `MCP_HOST` must resolve (A/AAAA) to the server **before** the stack starts.
2. Ports 80 and 443 must be reachable from the public internet.

Certificate state persists in the `caddy_data` named volume, so restarts and redeploys do not re-request certificates and do not risk hitting Let's Encrypt rate limits.

## Local development

`Caddyfile.dev` serves plain HTTP on port 80 (mapped to host `8080`). Add to `/etc/hosts`:

```
127.0.0.1   ops.local ops-mcp.local
```

Then browse `http://ops.local:8080`. No certificates, no HSTS — so the browser dev console does not complain during local work.
