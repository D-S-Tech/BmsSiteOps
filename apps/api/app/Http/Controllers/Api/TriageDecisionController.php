<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\TriageDecisionResource;
use App\Models\Site;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

/**
 * Triage decision audit log for a site. Read-only and tenant-scoped.
 */
class TriageDecisionController extends Controller
{
    /**
     * GET /api/v1/sites/{site}/triage-decisions — newest first, paginated.
     */
    public function index(Request $request, Site $site): AnonymousResourceCollection
    {
        $perPage = (int) min(100, max(1, $request->integer('per_page', 25)));

        $decisions = $site->triageDecisions()
            ->with('rule')
            ->orderByDesc('occurred_at')
            ->paginate($perPage);

        return TriageDecisionResource::collection($decisions);
    }
}
