<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Support\CurrentTenant;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Establishes the tenant context for authenticated API requests.
 *
 * Runs after auth:sanctum. Reads the authenticated user's current_tenant_id
 * and sets it as the active tenant for the request, so every tenant-scoped
 * query is filtered correctly. A user with no current tenant selected gets a
 * 409 — they must choose a tenant before using tenant-scoped endpoints.
 */
class SetTenantFromUser
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if ($user === null) {
            abort(401, 'Unauthenticated.');
        }

        if ($user->current_tenant_id === null) {
            abort(409, 'No active tenant selected for this user.');
        }

        CurrentTenant::set($user->current_tenant_id);

        return $next($request);
    }
}
