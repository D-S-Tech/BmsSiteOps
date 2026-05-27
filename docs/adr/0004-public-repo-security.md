# ADR 0004 — Public repo security posture

**Status:** Accepted
**Date:** 2026-05-27

## Context

The BmsSiteOps source code is hosted at [`github.com/D-S-Tech/BmsSiteOps`](https://github.com/D-S-Tech/BmsSiteOps) as a **public repository** from the first commit. This is a deliberate choice — public source matches the AGPL-3.0 license obligation (see [ADR 0005](./0005-license-agpl.md)), aids hiring and credibility, and reduces the cost of bringing on outside collaborators later.

Public source creates a security obligation that does not exist for private repositories. Every byte of every commit will be permanently world-readable. Git's history is forever — a credential committed once and removed later is still in the history, indexed by GitHub's search, scraped by bots within minutes, and effectively impossible to revoke.

## Decision

The following rules are non-negotiable for every contributor and every PR. Violations are treated as security incidents requiring credential rotation, not just a follow-up commit.

### Secrets

1. **No secrets in the repository, ever.** Not in code, not in tests, not in fixtures, not in documentation, not in screenshots, not in commit messages.
2. The only environment-style file in the repo is `.env.example`, which contains placeholder values (`changeme`, empty strings, or example URLs). It is committed.
3. The actual `.env` is gitignored. So is `.env.local`, `.env.staging`, `.env.production`, and any `.env.*` file other than examples.
4. Production credentials live in the production server's filesystem and an offline password manager. They are never sent over chat, email, or any tooling that retains history.
5. A pre-commit hook runs [`gitleaks`](https://github.com/gitleaks/gitleaks) (or equivalent) and rejects commits matching secret patterns.

### Customer data

1. Customer data — names, addresses, contact information, internal IP ranges, device hostnames, network topology — never enters the repository.
2. Seeders, fixtures, and tests use synthetic data only. The example tenant is "Demo Tenant"; example sites are "Demo Site #1", "Demo Site #2"; example device names follow a deterministic pattern (`vav-1.1`, `ahu-1`, `jace-supervisor-1`).
3. Documentation does not include screenshots that show customer data, even redacted. If a UI screenshot is needed for documentation, it is taken from a demo environment with synthetic data.

### In-database credentials

1. All credentials stored by BmsSiteOps to reach a customer's TRMM, Niagara, or BACnet endpoint use Laravel's `encrypted` cast.
2. The `APP_KEY` is regenerated per environment. The development key never matches the production key.
3. Credentials at rest are never logged. Laravel's log scrubber is configured to drop any field whose name matches `/password|token|secret|key|credential/i`.

### Transport and network

1. All external traffic uses TLS 1.3 minimum, enforced at Caddy.
2. Inter-service traffic stays on the Docker bridge network. The PostgreSQL, Redis, Meilisearch, and worker ports are not published to the host or the internet.
3. Caddy emits HSTS with a 1-year max-age and preload directive in production.
4. The MCP endpoint, the API, and the admin panel all enforce authentication; there are no unauthenticated endpoints except `/health` and `/up`.

### Audit

1. An `audit_log` table records every state-changing API call: actor (user_id + tenant_id), action, target resource, before/after JSON, IP address, user-agent, timestamp.
2. The audit log is append-only — no UPDATE or DELETE statements against it from application code.
3. Audit logs are retained for 13 months minimum.

### Tenant isolation

1. Every Eloquent model representing tenant data has a unit test that asserts: (a) it cannot be created without a tenant in scope, (b) a query without tenant scope throws, (c) it never appears in a query for a different tenant.
2. A CI job runs the full tenant-isolation test suite on every PR. A failure blocks merge.

### Dependency hygiene

1. Dependabot is enabled for `composer`, `npm`, and `pip` ecosystems.
2. CI runs `composer audit`, `npm audit --audit-level=high`, and `pip-audit` on every PR.
3. A high-severity advisory blocks merge until resolved or explicitly accepted in writing.

### Reporting

A `SECURITY.md` at the repo root describes how to report a vulnerability privately (GitHub's private vulnerability reporting feature, with an email fallback). Public issues for security matters are explicitly discouraged.

## Consequences

**Positive**

- The blast radius of any single mistake is bounded — no credential leak compounds because there are no credentials in the repo to leak.
- New collaborators inherit the security posture by reading this ADR, not by being told things ad-hoc.
- The project is presentable to customers from a security-due-diligence standpoint without scrambling.

**Negative**

- Some developer-convenience features (committing a seeded `.env.local` for instant onboarding) are not available. Replaced by clear `.env.example` plus a documented setup script.
- Pre-commit hooks add friction. Accepted.

## Alternatives considered

- **Private repo** would reduce the security obligations but loses the credibility, hiring, and collaboration benefits. The discipline required for a public repo is also healthy discipline for a private one.
- **Public but with secrets in a Git submodule pointing at a private repo** — fragile, easy to misconfigure, and the failure mode is exactly the one we are trying to prevent.

## See also

- [ADR 0005 — License: AGPL-3.0](./0005-license-agpl.md)
- [`SECURITY.md`](../../SECURITY.md) — once created
