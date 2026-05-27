# BmsSiteOps API — Laravel 13 backend

This is the Laravel 13 application backing BmsSiteOps. It hosts:

- The REST API at `/api/v1/*` (Sanctum-authenticated, bearer tokens)
- The Filament 5 admin panel at `/admin`
- The Horizon queue dashboard at `/horizon` (IP-restricted in production)
- The multi-tenant data model (tenants, users, sites, sources, devices, events)
- Background workers (queued jobs via Horizon)

## Running locally

The API is run via Docker Compose from the repository root:

```bash
make dev-up-all          # brings up everything (postgres, redis, meilisearch, api, web, worker, caddy)
make sh-api              # shell into the api container
make api-migrate         # run database migrations
make api-fresh           # drop + recreate + migrate + seed (DESTRUCTIVE)
make api-test            # run the PHPUnit suite
make api-pint            # run Laravel Pint formatter
```

The PHP version, extensions, and Composer install are baked into the `api` Dockerfile at [`infra/docker/api.Dockerfile`](../../infra/docker/api.Dockerfile). The application uses PHP 8.3 with `pdo_pgsql`, `redis`, `gd`, `intl`, `bcmath`, `opcache`, and `pcntl` extensions.

## Multi-tenancy

Every tenant-scoped Eloquent model uses the `App\Models\Concerns\BelongsToTenant` trait. The trait applies the `App\Models\Scopes\TenantScope` global scope (filtering queries to the current tenant) and the `creating()` hook sets `tenant_id` from `App\Support\CurrentTenant::id()`.

The current tenant is resolved from (in order):

1. `App\Support\CurrentTenant::set($tenant)` — explicit set, used by tests, queue jobs, and the tenant-switching middleware.
2. The authenticated user's `current_tenant_id` column.
3. `null` — no tenant in scope.

When no tenant is in scope, queries against tenant-scoped models return empty results (read-safe) and writes throw `App\Exceptions\NoTenantInScopeException` (write-safe).

The first model demonstrating the pattern is `App\Models\Site`. Adding a new tenant-scoped model: extend `Model`, apply `BelongsToTenant`, add `tenant_id` foreign key in the migration, ensure any composite unique index includes `tenant_id` first.

See [`docs/adr/0002-multi-tenancy-row-level.md`](../../docs/adr/0002-multi-tenancy-row-level.md) for the full design rationale.

## Tests

`tests/Feature/Tenancy/TenantScopeTest.php` is the canonical reference for tenant-isolation tests. Every new tenant-scoped model must pass equivalent assertions:

- Creating without a tenant in scope throws.
- Creating sets `tenant_id` from the current tenant.
- Queries only return rows for the current tenant.
- Queries with no tenant in scope return empty.
- `withoutGlobalScope(TenantScope::class)` bypasses the filter for super-admin paths.
- Explicit `tenant_id` assignment is honored (cross-tenant operations).

Run:

```bash
make api-test                                    # full suite
make sh-api
php artisan test --filter=TenantScope            # specific test
```

## Filament admin panel

The Filament 5 panel is registered via `app/Providers/Filament/AdminPanelProvider.php` and mounts at `/admin`. New resources live in `app/Filament/Resources/`. Generate one with:

```bash
make sh-api
php artisan make:filament-resource <Name> --generate
```

Every Filament resource for a tenant-scoped model automatically respects `TenantScope` because the underlying Eloquent model does.
