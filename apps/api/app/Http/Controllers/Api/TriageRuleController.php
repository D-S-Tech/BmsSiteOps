<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\TriageRuleResource;
use App\Models\TriageRule;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

/**
 * Read access to triage rules. Tenant-scoped via the route's 'tenant'
 * middleware and the model's global scope. CRUD is added in Sprint 5.2 via
 * Filament for operators.
 */
class TriageRuleController extends Controller
{
    /**
     * GET /api/v1/triage-rules — enabled-first, then by priority, paginated.
     */
    public function index(Request $request): AnonymousResourceCollection
    {
        $perPage = (int) min(100, max(1, $request->integer('per_page', 25)));

        $rules = TriageRule::query()
            ->orderByDesc('enabled')
            ->orderBy('priority')
            ->orderBy('id')
            ->paginate($perPage);

        return TriageRuleResource::collection($rules);
    }

    /**
     * GET /api/v1/triage-rules/{rule}
     */
    public function show(TriageRule $rule): TriageRuleResource
    {
        return TriageRuleResource::make($rule);
    }
}
