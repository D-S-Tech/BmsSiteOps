<?php

declare(strict_types=1);

namespace App\Models\Scopes;

use App\Support\CurrentTenant;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Scope;

/**
 * Global scope that filters every query to the currently active tenant.
 *
 * Applied automatically by the App\Models\Concerns\BelongsToTenant trait.
 * Can be bypassed with ->withoutGlobalScope(TenantScope::class) for legitimate
 * cross-tenant queries (super-admin reporting, system maintenance jobs).
 *
 * If no tenant is in scope at query time, the trait's boot logic throws — we
 * fail closed, never returning an unfiltered result set.
 *
 * See: docs/adr/0002-multi-tenancy-row-level.md
 */
class TenantScope implements Scope
{
    public function apply(Builder $builder, Model $model): void
    {
        $tenantId = CurrentTenant::id();

        // If no tenant is in scope, force an empty result. The trait's
        // creating() hook throws to prevent writes; reads silently return [].
        // This is intentional: reads should be safe to call from any context
        // (e.g., a queued job that lost its tenant context), writes must not.
        if ($tenantId === null) {
            $builder->whereRaw('1 = 0');

            return;
        }

        $builder->where($model->qualifyColumn('tenant_id'), $tenantId);
    }
}
