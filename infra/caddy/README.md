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


## MCP endpoint hardening _(Sprint 8.5)_

The MCP SSE endpoint at `${MCP_HOST}` is **personal-use** — anyone who can reach it can act as the operator whose Sanctum token is in `MCP_API_TOKEN`. Two protection layers ship with the platform; both default to permissive so existing deploys keep working, and both are activated through `.env` and a single config file.

### Layer 1 — IP allowlist (always-on, configurable)

The `Caddyfile` declares a matcher that rejects any client outside `${MCP_IP_ALLOWLIST}`:

```caddy
@mcp_blocked_ip not remote_ip {$MCP_IP_ALLOWLIST:0.0.0.0/0 ::/0}
respond @mcp_blocked_ip "Forbidden: client IP not in MCP_IP_ALLOWLIST" 403
```

Default value `0.0.0.0/0 ::/0` allows everyone (no breaking change). Tighten in `.env`:

```bash
# Allow only your office network + a single VPN exit IP + IPv6 prefix
MCP_IP_ALLOWLIST="10.0.0.0/8 198.51.100.42/32 2001:db8::/32"
```

CIDRs are **space-separated**, not comma-separated — Caddy's `remote_ip` matcher uses space-delimited lists. After editing `.env`, restart Caddy:

```bash
make prod-restart
```

### Layer 2 — HTTP Basic Auth (opt-in)

The `Caddyfile` unconditionally `import`s `mcp-basic-auth.conf`. By default this file is a **stub** (just comments) so the import contributes nothing. Replace it to enable:

```bash
# 1. Generate a bcrypt hash for your password (no local caddy install needed):
make mcp-gen-credentials

# 2. Drop the hash into .env on the prod host:
echo 'MCP_BASIC_AUTH_HASH=$2a$14$abcdef...' >> .env

# 3. Activate the directive by swapping the stub for the example template:
cp infra/caddy/mcp-basic-auth.conf.example infra/caddy/mcp-basic-auth.conf

# 4. Restart Caddy:
make prod-restart
```

Test it:

```bash
# Without credentials -> 401
curl -i https://${MCP_HOST}/sse

# With credentials -> 200
curl -i -u operator:<your password> https://${MCP_HOST}/sse
```

The example template uses one username (`operator`) and the env-sourced hash. Add additional users on additional lines if multiple humans need separate credentials — each user can have its own hash variable.

### Layering both

The two protections compose naturally. A request to the MCP host is:

1. Checked against `MCP_IP_ALLOWLIST` → 403 if outside.
2. Checked against `basic_auth` → 401 if no/wrong credentials.
3. Proxied to the worker.

For production deployments where the MCP server is reachable from the internet, **use both** — IP allowlist alone leaks no information when the password is wrong; basic_auth alone requires you to trust HTTPS as the only confidentiality boundary.

### CI validation

The `compose-validate` workflow job runs `caddy validate` over the Caddyfile in **four configurations**: default (no restrictions), IP allowlist set, basic_auth enabled (via the `.example` template), and both layered. All four must parse for a PR to merge.
