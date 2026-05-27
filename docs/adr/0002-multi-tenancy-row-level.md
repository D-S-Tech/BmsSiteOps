# ADR 0002 — Multi-tenancy: row-level

**Status:** Accepted
**Date:** 2026-05-27

## Context

BmsSiteOps starts as an internal tool for BMCE but is explicitly designed with commercialization as a near-term option. The data model must support multiple isolated tenants from day one. Retrofitting tenancy into a single-tenant codebase later is expensive and error-prone.

Three tenancy models are commonly used in SaaS platforms:

1. **Row-level** — every tenant-scoped table carries a `tenant_id` column; queries are filtered automatically by a global scope.
2. **Schema-per-tenant** — each tenant gets its own PostgreSQL schema with identical tables.
3. **Database-per-tenant** — each tenant gets a separate database (or even a separate database server).

The product's expected operating profile for the next two years:

- 1–20 tenants total (BMCE plus a handful of pilot customers).
- Tenant size: small to medium (hundreds to low thousands of devices per tenant).
- Workload: read-heavy with bursty write patterns from collectors.

## Decision

**Row-level multi-tenancy** with a `tenant_id` foreign key on every tenant-scoped table and a Laravel global scope (`App\Models\Scopes\TenantScope`) that automatically constrains queries to the authenticated tenant.

Implementation rules:

1. Every Eloquent model representing tenant data extends `App\Models\Concerns\BelongsToTenant`.
2. The trait automatically applies `TenantScope` on `booted()`.
3. The trait automatically sets `tenant_id` on `creating()`, refusing to save if no tenant is in scope.
4. The current tenant is resolved from the authenticated user's `current_tenant_id` (a many-to-many `tenant_user` relation allows users to belong to multiple tenants and switch contexts).
5. Database-level enforcement: composite unique indexes always include `tenant_id` (e.g., `unique(tenant_id, name)` on `sites`).
6. Every endpoint and Filament resource passes through tenant-aware middleware that fails closed if no tenant is resolved.

## Consequences

**Positive**

- Operational simplicity: one database, one schema, one set of migrations. Backups, monitoring, and migrations work the same way they do in any single-tenant Laravel app.
- Cheap to add a tenant — a row in the `tenants` table is enough.
- Reporting and cross-tenant analytics (for the BMCE super-admin role) are trivial — drop the global scope when needed.
- Filament works naturally with row-level scope; no custom panel-per-tenant infrastructure required.

**Negative**

- A bug in scope enforcement leaks data across tenants. **Mitigation:** automated tests assert that any model query without a tenant in scope throws, plus a static analysis check for raw queries against tenant-scoped tables.
- "Noisy neighbor" — one tenant's heavy collector can affect others. **Mitigation:** Horizon per-tenant queue priority, and per-tenant rate limits on the collector worker.
- Future enterprise customers may demand stricter isolation. **Mitigation:** the row-level model can later be augmented with schema-per-tenant for specific large customers without breaking the small-tenant code path.

## Alternatives considered

- **Schema-per-tenant** gives strong isolation but multiplies migration complexity, complicates Filament (one panel per tenant?), and forces a connection switch per request. The operational pain outweighs the isolation benefit at our scale.
- **Database-per-tenant** is the strongest isolation but is operationally inappropriate for 1–20 tenants. Reasonable only at the largest scale or when regulatory residency requires it.

## See also

- [ADR 0004 — Public repo security posture](./0004-public-repo-security.md) — describes the testing requirements for tenant isolation.
