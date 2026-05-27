<?php

declare(strict_types=1);

namespace App\Models\Concerns;

use App\Exceptions\NoTenantInScopeException;
use App\Models\Scopes\TenantScope;
use App\Models\Tenant;
use App\Support\CurrentTenant;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Apply this trait to any Eloquent model whose rows belong to a tenant.
 *
 * It does three things:
 *
 *   1. Adds a tenant() BelongsTo relation.
 *   2. Adds App\Models\Scopes\TenantScope as a global scope, so every query
 *      against the model is automatically filtered to the current tenant.
 *   3. On creating, sets tenant_id from the current tenant context, throwing
 *      App\Exceptions\NoTenantInScopeException if no tenant is in scope.
 *
 * Models using this trait must:
 *   - Have a tenant_id column (foreign key to tenants table)
 *   - Have any composite unique index include tenant_id as the first column
 *
 * See: docs/adr/0002-multi-tenancy-row-level.md
 */
trait BelongsToTenant
{
    public static function bootBelongsToTenant(): void
    {
        static::addGlobalScope(new TenantScope);

        static::creating(function ($model): void {
            // Allow explicit tenant_id assignment (super-admin operations).
            if ($model->tenant_id !== null) {
                return;
            }

            $tenantId = CurrentTenant::id();

            if ($tenantId === null) {
                throw new NoTenantInScopeException(
                    sprintf(
                        'Attempted to create %s without a tenant in scope. '
                        .'Set the current tenant via CurrentTenant::set($tenant) '
                        .'before instantiating tenant-scoped models.',
                        static::class
                    )
                );
            }

            $model->tenant_id = $tenantId;
        });
    }

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }
}
