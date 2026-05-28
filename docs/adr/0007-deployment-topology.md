# ADR 0007 — Deployment topology

**Status:** Accepted
**Date:** 2026-05-27

## Context

[ADR 0003](./0003-stack-choices.md) chose Docker + Docker Compose as the runtime and noted that the first deployment target is "a single LXC container for the first year." This ADR records the deployment topology in full: how many hosts, how the stack is laid out on them, how deploys happen, and what conditions would force a change.

The forces in play:

- The platform is pre-alpha, single-customer (BMCE) at launch, with multi-tenancy built into the data model but not yet exercised by real external tenants.
- Expected load is operator-scale: a handful of concurrent sessions and a steady but modest stream of background collector work — not public web traffic.
- The maintainer operates the host directly; there is no dedicated platform/SRE team.
- The existing infrastructure is a Virtualizor cluster where new LXC containers and VMs are cheap to create.

## Decision

**Deploy the entire stack as Docker Compose on a single Ubuntu 24.04 host (LXC or VM).**

- One host runs every service: postgres, redis, meilisearch, api, api-horizon, api-scheduler, web, worker, caddy.
- Caddy is the only service with published ports (80, 443/tcp, 443/udp). Everything else is reachable only on the internal Docker bridge network.
- Deploys are git-pull-based: `deploy.sh` pulls `main`, rebuilds images on the host, runs migrations, and restarts the stack.
- First-run host setup is scripted in `bootstrap-server.sh` (firewall, fail2ban, Docker, deploy user, hardening).
- TLS certificates are obtained and renewed automatically by Caddy via Let's Encrypt.
- Secrets live only in the host's `.env` file, never in the repository or images.

## Consequences

**Positive**

- **Operational simplicity.** One host to patch, monitor, back up, and reason about. `docker compose ps` shows the entire system state on one screen.
- **Cheap and fast.** A new LXC on the existing Virtualizor cluster costs minutes to create. No managed-service bills, no cloud lock-in.
- **Build-on-host keeps the registry out of the loop.** No need to run, secure, and pay for a container registry during early development. The host builds exactly the commit it runs.
- **Recovery is a re-clone.** The host holds no irreplaceable state except `.env` and the database/Meilisearch volumes. Rebuilding the host is: provision, bootstrap, clone, restore `.env`, restore DB dump, deploy.
- **Self-contained deploy story.** `bootstrap-server.sh` + `deploy.sh` + `DEPLOYMENT.md` mean anyone (including future-self) can reproduce production from the public repo plus a secrets file.

**Negative**

- **Single point of failure.** If the host dies, the platform is down until it is restored. Acceptable at this stage: the SLA is "best effort for one operator's own business," and recovery is scripted. Mitigated by off-host database backups.
- **No horizontal scaling.** Vertical scaling (bigger LXC) is the only growth lever. Fine until concurrent load or background work outgrows a single sizeable host — which is far away.
- **Build-on-host couples deploy time to build time.** A cold rebuild takes a few minutes. Acceptable for a system that deploys a few times a day at most. The Compose layer cache keeps warm rebuilds short.
- **Migrations run inline with deploy.** A long migration extends the deploy window. At current data volumes this is negligible; revisit if a migration ever needs an online/zero-downtime strategy.

## What would trigger a change

This topology is deliberately the simplest thing that works. Move off it when — and only when — one of these becomes true:

1. **Real multi-tenant load** — several paying tenants whose uptime expectations justify removing the single point of failure. → Introduce a second app host + managed/replicated PostgreSQL, put Caddy (or a load balancer) in front of multiple app replicas.
2. **Background work saturates the host** — collectors + AI jobs starve the web tier. → Split the worker onto its own host first (it is already a separate service and image).
3. **Deploy time hurts** — build-on-host becomes a bottleneck. → Add a container registry and a CI build-and-push pipeline; hosts pull pre-built images instead of building.
4. **Compliance / SLA demands** — a customer contract requires documented HA, RTO/RPO targets, or geographic redundancy. → Re-platform onto an orchestrator (k8s or Nomad) with the topology those targets dictate.

Until then, a single well-backed-up host is the correct amount of infrastructure.

## See also

- [ADR 0003 — Stack choices](./0003-stack-choices.md) — chose Docker Compose as the runtime.
- [ADR 0006 — Containerization patterns](./0006-containerization-patterns.md) — how each container is built.
- [`docs/DEPLOYMENT.md`](../DEPLOYMENT.md) — the operator runbook implementing this topology.
- [`infra/scripts/bootstrap-server.sh`](../../infra/scripts/bootstrap-server.sh) and [`infra/scripts/deploy.sh`](../../infra/scripts/deploy.sh) — the scripts.
