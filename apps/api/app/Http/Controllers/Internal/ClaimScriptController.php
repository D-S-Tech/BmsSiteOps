<?php

declare(strict_types=1);

namespace App\Http\Controllers\Internal;

use App\Enums\ScriptStatus;
use App\Http\Controllers\Controller;
use App\Http\Resources\ScriptResource;
use App\Models\Script;
use App\Support\CurrentTenant;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\DB;

/**
 * Internal endpoint: a worker claims the next pending script generation.
 *
 * Atomically (under a row lock) finds the oldest Requested script across all
 * tenants, flips it to Generating, stamps claimed_at, and returns it. Returns
 * 204 No Content when the queue is empty.
 *
 * Concurrency: lockForUpdate() prevents two workers in the same transaction
 * from grabbing the same row. On PostgreSQL with multiple worker processes,
 * the second worker blocks until the first commits. For higher throughput
 * (skip-locked semantics) we'd add a raw 'FOR UPDATE SKIP LOCKED' — deferred
 * until multi-worker becomes a real load profile. CI runs SQLite, which
 * locks the whole database for the transaction; functionally equivalent.
 */
class ClaimScriptController extends Controller
{
    public function __invoke(): ScriptResource|Response
    {
        $claimed = DB::transaction(function (): ?Script {
            $script = Script::withoutGlobalScopes()
                ->where('status', ScriptStatus::Requested->value)
                ->orderBy('requested_at')
                ->orderBy('id')
                ->lockForUpdate()
                ->first();

            if ($script === null) {
                return null;
            }

            // Tenant context lets the model's BelongsToTenant trait keep its
            // invariants (and any side-effects respect tenant scope).
            CurrentTenant::set($script->tenant_id);

            $script->forceFill([
                'status' => ScriptStatus::Generating,
                'claimed_at' => now(),
            ])->save();

            return $script;
        });

        if ($claimed === null) {
            return response()->noContent();
        }

        return ScriptResource::make($claimed);
    }
}
