<?php

declare(strict_types=1);

namespace App\Support;

use App\Models\Tenant;
use Illuminate\Support\Facades\Auth;

/**
 * Holds the currently active tenant context for the request lifecycle.
 *
 * Resolution order:
 *   1. Explicitly set via CurrentTenant::set($tenantOrId) — used by tests,
 *      queued jobs, and the tenant-switching middleware.
 *   2. From the authenticated user's `current_tenant_id` column.
 *   3. null — no tenant in scope.
 *
 * When no tenant is in scope, tenant-scoped queries return empty results
 * (read-safe) and writes throw NoTenantInScopeException (write-safe).
 *
 * See: docs/adr/0002-multi-tenancy-row-level.md
 */
class CurrentTenant
{
    private static ?int $tenantId = null;

    /**
     * Set the current tenant for the rest of the request.
     */
    public static function set(Tenant|int|null $tenant): void
    {
        self::$tenantId = match (true) {
            $tenant instanceof Tenant => $tenant->id,
            is_int($tenant) => $tenant,
            default => null,
        };
    }

    /**
     * Get the current tenant ID, or null if none is in scope.
     */
    public static function id(): ?int
    {
        if (self::$tenantId !== null) {
            return self::$tenantId;
        }

        $user = Auth::user();

        if ($user === null) {
            return null;
        }

        // The User model exposes current_tenant_id when authenticated.
        return $user->current_tenant_id ?? null;
    }

    /**
     * Get the current tenant model, or null if none is in scope.
     */
    public static function get(): ?Tenant
    {
        $id = self::id();

        return $id !== null ? Tenant::withoutGlobalScopes()->find($id) : null;
    }

    /**
     * Clear any explicitly set tenant context. Falls back to the authenticated
     * user's current_tenant_id. Used by tests between cases.
     */
    public static function forget(): void
    {
        self::$tenantId = null;
    }
}
