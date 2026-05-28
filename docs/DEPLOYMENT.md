# Deployment runbook

This document describes how to deploy BmsSiteOps to a production host from a clean slate. It contains **no environment-specific values** — every host, domain, and credential is a placeholder you fill in for your own deployment.

The target architecture for the first deployment is a single Linux container (LXC) or VM running the full Docker Compose stack behind Caddy. See [ADR 0007](adr/0007-deployment-topology.md) for why a single host is the right call at this stage and what would trigger a move to multiple nodes.

---

## Prerequisites

| Requirement | Detail |
|---|---|
| Host | Ubuntu 24.04 LTS, 4 vCPU / 8 GB RAM / 100 GB disk (minimum for the full stack) |
| Access | root (or sudo) for first-run bootstrap; a non-root deploy user thereafter |
| DNS control | Ability to create A/AAAA records for your domain |
| Domains | One for the app (`<YOUR_DOMAIN>`) and one for the MCP endpoint (`<YOUR_MCP_DOMAIN>`) |
| Anthropic API key | Required for the AI features (Sprint 4+) |

---

## Overview

```
1. Provision host  ──►  2. Bootstrap  ──►  3. DNS  ──►  4. Clone + .env  ──►  5. Deploy  ──►  6. Verify
   (LXC / VM)            (script)           (A/AAAA)     (fill secrets)         (script)        (curl)
```

---

## 1. Provision the host

Create an Ubuntu 24.04 LXC container or VM with at least the resources in the prerequisites table. Ensure it has a public IPv4 (and ideally IPv6) address and that ports **80**, **443**, and your **SSH port** are reachable.

> The specifics of provisioning depend on your hypervisor or cloud. This runbook starts from "I can SSH into a fresh Ubuntu 24.04 host as root."

---

## 2. Bootstrap the server

Copy the repository's bootstrap script to the host (or clone the repo as root first) and run it:

```bash
sudo ./infra/scripts/bootstrap-server.sh
```

This is idempotent and performs:

- System update + baseline packages (`git`, `make`, `jq`, `curl`, …)
- Unattended security upgrades
- UFW firewall — allows SSH + 80 + 443 (TCP) + 443 (UDP for HTTP/3), denies everything else
- fail2ban for SSH brute-force protection
- Docker Engine + Compose plugin
- A non-root `deploy` user in the `docker` group
- Kernel + journald hardening

Customize via environment variables if needed:

```bash
sudo DEPLOY_USER=ops SSH_PORT=2222 ./infra/scripts/bootstrap-server.sh
```

After it finishes, add your deploy user's SSH public key:

```bash
# from your workstation
ssh-copy-id -i ~/.ssh/your_key.pub deploy@<SERVER_IP>
# or manually append to /home/deploy/.ssh/authorized_keys on the host
```

---

## 3. Configure DNS

Create records pointing at the server, at your DNS provider:

| Type | Name | Value | TTL |
|---|---|---|---|
| A | `<YOUR_DOMAIN>` | `<SERVER_IPV4>` | 300 |
| AAAA | `<YOUR_DOMAIN>` | `<SERVER_IPV6>` | 300 |
| A | `<YOUR_MCP_DOMAIN>` | `<SERVER_IPV4>` | 300 |
| AAAA | `<YOUR_MCP_DOMAIN>` | `<SERVER_IPV6>` | 300 |

Wait for propagation before deploying — Caddy needs the domains to resolve to the server in order to obtain Let's Encrypt certificates. Verify:

```bash
dig +short <YOUR_DOMAIN>
dig +short <YOUR_MCP_DOMAIN>
```

Both must return your server's IP.

---

## 4. Clone the repository and create `.env`

As the deploy user:

```bash
su - deploy
git clone https://github.com/D-S-Tech/BmsSiteOps.git
cd BmsSiteOps

cp infra/compose/.env.prod.example .env
```

Now edit `.env` and replace **every** `CHANGEME` and `<YOUR_...>` placeholder. Generate strong secrets with:

```bash
openssl rand -base64 32
```

Required at minimum:

- `APP_KEY` — generate with `docker compose -f infra/compose/docker-compose.prod.yml run --rm api php artisan key:generate --show`
- `APP_URL`, `WEB_HOST`, `API_HOST`, `MCP_HOST`, `ACME_EMAIL`
- `DB_PASSWORD`, `MEILISEARCH_KEY`, `LITELLM_MASTER_KEY`, `WORKER_INTERNAL_KEY`
- `REVERB_APP_KEY`, `REVERB_APP_SECRET`
- `ANTHROPIC_API_KEY` (for AI features)
- Mail settings if you want outbound email

The deploy script refuses to run while any `CHANGEME` or `<YOUR_...>` placeholder remains, as a guardrail.

---

## 5. Deploy

```bash
./infra/scripts/deploy.sh
```

The script:

1. Pulls the latest code (`git pull --ff-only`)
2. Builds the api / web / worker images
3. Starts postgres, redis, meilisearch and waits for postgres to report healthy
4. Runs `php artisan migrate --force`
5. Brings up the full application tier and warms Laravel caches
6. Prints the stack status and the external health-check command

Useful flags:

```bash
./infra/scripts/deploy.sh --no-pull       # deploy the current checkout
./infra/scripts/deploy.sh --no-build      # reuse existing images
./infra/scripts/deploy.sh --skip-migrate  # skip migrations this run
```

For routine redeploys you can also use the Makefile target:

```bash
make prod-deploy
```

---

## 6. Verify

```bash
# From the host or anywhere:
curl -I https://<YOUR_DOMAIN>/health
```

Expect `HTTP/2 200` and a `strict-transport-security` header. Then check:

| Check | Command / URL |
|---|---|
| Web app | open `https://<YOUR_DOMAIN>` |
| Admin panel | open `https://<YOUR_DOMAIN>/admin` |
| MCP endpoint | `curl -I https://<YOUR_MCP_DOMAIN>` |
| Container status | `make prod-ps` |
| Logs | `docker compose -f infra/compose/docker-compose.prod.yml logs -f` |

Set up an external uptime monitor (e.g. a 60-second HTTPS check on `/health`) so you learn about an outage before a tenant does.

---

## Routine operations

| Task | Command |
|---|---|
| Redeploy latest `main` | `make prod-deploy` |
| View status | `make prod-ps` |
| Tail logs | `docker compose -f infra/compose/docker-compose.prod.yml logs -f` |
| Open a Laravel shell | `docker compose -f infra/compose/docker-compose.prod.yml exec api bash` |
| Run an artisan command | `docker compose -f infra/compose/docker-compose.prod.yml exec api php artisan <cmd>` |
| Stop the stack | `make prod-down` |

---

## Backups

> Backups are an operator responsibility and are not automated by this repository yet (tracked for a future sprint). At minimum, schedule:

- **PostgreSQL** — `pg_dump` of the `postgres` container volume, off-host, daily.
- **Meilisearch** — snapshot of the `meilisearch_data` volume (rebuildable from Postgres, so lower priority).
- **`.env`** — store a copy in a password manager or secrets vault. It is the one unrecoverable file on the host.

Example ad-hoc database dump:

```bash
docker compose -f infra/compose/docker-compose.prod.yml exec -T postgres \
    pg_dump -U bmssiteops bmssiteops | gzip > backup-$(date +%F).sql.gz
```

---

## Rollback

Because images are built from a specific git commit, rolling back is checking out the previous commit and redeploying:

```bash
git log --oneline -n 10          # find the last good commit
git checkout <GOOD_COMMIT_SHA>
./infra/scripts/deploy.sh --no-pull
```

If a migration needs to be reversed, do so explicitly before redeploying the older image:

```bash
docker compose -f infra/compose/docker-compose.prod.yml exec api php artisan migrate:rollback
```

Then return to `main` once the issue is fixed:

```bash
git checkout main
```

---

## CI/CD (optional)

A manual-trigger GitHub Actions workflow is provided at [`.github/workflows/deploy.yml`](../.github/workflows/deploy.yml). It SSHes into the host and runs `deploy.sh`. It is **disabled by default** (runs only via `workflow_dispatch`) and reads all connection details from GitHub repository secrets — nothing is hardcoded. See the workflow file's header comment for the required secrets.
