<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\EventResource;
use App\Http\Resources\SiteResource;
use App\Models\Site;
use App\Services\SiteContextService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Aggregated, operator-facing views of a single site for the dashboard.
 *
 * Tenant-scoped automatically (models carry the BelongsToTenant global scope,
 * route is behind the 'tenant' middleware). All aggregation lives in
 * SiteContextService so the dashboard and the AI brief-context share one
 * source of truth.
 */
class SiteDashboardController extends Controller
{
    public function __construct(private readonly SiteContextService $context) {}

    /**
     * GET /api/v1/sites/{site}/summary
     *
     * Device status breakdown, source health, and a 24h event-severity
     * rollup with the most recent actionable events.
     */
    public function summary(Site $site): JsonResponse
    {
        return response()->json([
            'site' => SiteResource::make($site),
            'devices' => $this->context->deviceBreakdown($site),
            'sources' => $this->context->sourceBreakdown($site),
            'events_24h' => $this->context->eventSeverityBreakdown($site, now()->subDay()),
            'recent_events' => EventResource::collection(
                $this->context->recentActionableEvents($site)
            ),
        ]);
    }

    /**
     * GET /api/v1/sites/{site}/timeline?hours=24
     *
     * Hourly event counts by severity over the requested window.
     */
    public function timeline(Site $site, Request $request): JsonResponse
    {
        return response()->json(
            $this->context->hourlyTimeline($site, $request->integer('hours', 24))
        );
    }
}
