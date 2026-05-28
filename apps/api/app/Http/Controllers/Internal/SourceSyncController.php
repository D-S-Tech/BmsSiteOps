<?php

declare(strict_types=1);

namespace App\Http\Controllers\Internal;

use App\Http\Controllers\Controller;
use App\Http\Requests\Internal\SourceSyncRequest;
use App\Models\Source;
use App\Services\Ingestion\SourceSyncService;
use Illuminate\Http\JsonResponse;

/**
 * Internal ingestion endpoint called by the Python worker.
 *
 * Authenticated by the VerifyWorkerSignature middleware (HMAC), not by a user
 * session. The source is resolved WITHOUT the tenant global scope because no
 * tenant context exists yet at the HTTP layer — the SourceSyncService sets the
 * tenant from the source itself before touching tenant-scoped tables.
 */
class SourceSyncController extends Controller
{
    public function __construct(private readonly SourceSyncService $sync) {}

    public function __invoke(SourceSyncRequest $request, int $sourceId): JsonResponse
    {
        // No tenant in scope at this layer — resolve across all tenants. The
        // worker is trusted (HMAC-verified) and the source's tenant_id is the
        // authority for everything that follows.
        $source = Source::withoutGlobalScopes()->findOrFail($sourceId);

        $result = $this->sync->sync($source, $request->validated());

        return response()->json([
            'source_id' => $source->id,
            'devices_synced' => $result['devices_synced'],
            'events_ingested' => $result['events_ingested'],
            'triage_decisions' => $result['triage_decisions'],
        ]);
    }
}
