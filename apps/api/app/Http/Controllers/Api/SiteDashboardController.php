<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\EventResource;
use App\Http\Resources\SiteResource;
use App\Models\Device;
use App\Models\Event;
use App\Models\Site;
use App\Models\Source;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;

/**
 * Aggregated, operator-facing views of a single site for the dashboard.
 *
 * Everything here is tenant-scoped automatically (the models carry the
 * BelongsToTenant global scope and the route is behind the 'tenant'
 * middleware). Aggregations use portable SQL (groupBy + count) so they run
 * identically on PostgreSQL and the SQLite test database; the timeline is
 * bucketed in PHP for the same reason.
 */
class SiteDashboardController extends Controller
{
    /**
     * GET /api/v1/sites/{site}/summary
     *
     * Device status breakdown, source health, and a 24h event-severity
     * rollup with the most recent actionable events.
     */
    public function summary(Site $site): JsonResponse
    {
        $since = now()->subDay();

        return response()->json([
            'site' => SiteResource::make($site),
            'devices' => $this->deviceBreakdown($site),
            'sources' => $this->sourceBreakdown($site),
            'events_24h' => $this->eventSeverityBreakdown($site, $since),
            'recent_events' => EventResource::collection(
                Event::query()
                    ->where('site_id', $site->id)
                    ->whereIn('severity', ['critical', 'warning'])
                    ->orderByDesc('occurred_at')
                    ->limit(10)
                    ->get()
            ),
        ]);
    }

    /**
     * GET /api/v1/sites/{site}/timeline?hours=24
     *
     * Hourly event counts by severity over the requested window. Buckets are
     * computed in PHP so the query stays database-agnostic.
     */
    public function timeline(Site $site, Request $request): JsonResponse
    {
        $hours = max(1, min(168, $request->integer('hours', 24)));
        $from = now()->subHours($hours)->startOfHour();
        $to = now();

        $events = Event::query()
            ->where('site_id', $site->id)
            ->where('occurred_at', '>=', $from)
            ->orderBy('occurred_at')
            ->get(['occurred_at', 'severity']);

        // Seed every hour bucket with zero counts so the timeline is contiguous.
        $buckets = [];
        for ($cursor = $from->copy(); $cursor < $to; $cursor->addHour()) {
            $buckets[$cursor->format('Y-m-d H:00')] = [
                't' => $cursor->copy()->toIso8601String(),
                'critical' => 0,
                'warning' => 0,
                'info' => 0,
                'none' => 0,
                'total' => 0,
            ];
        }

        foreach ($events as $event) {
            $key = Carbon::parse($event->occurred_at)->format('Y-m-d H:00');
            if (! isset($buckets[$key])) {
                continue;
            }
            $severity = $event->severity?->value ?? 'none';
            $buckets[$key][$severity]++;
            $buckets[$key]['total']++;
        }

        return response()->json([
            'bucket' => 'hour',
            'from' => $from->toIso8601String(),
            'to' => $to->toIso8601String(),
            'buckets' => array_values($buckets),
        ]);
    }

    /**
     * @return array<string, int>
     */
    private function deviceBreakdown(Site $site): array
    {
        $counts = Device::query()
            ->where('site_id', $site->id)
            ->selectRaw('status, count(*) as c')
            ->groupBy('status')
            ->pluck('c', 'status');

        return [
            'total' => (int) $counts->sum(),
            'online' => (int) $counts->get('online', 0),
            'offline' => (int) $counts->get('offline', 0),
            'unknown' => (int) $counts->get('unknown', 0),
        ];
    }

    /**
     * @return array<string, int>
     */
    private function sourceBreakdown(Site $site): array
    {
        $counts = Source::query()
            ->where('site_id', $site->id)
            ->selectRaw('last_status, count(*) as c')
            ->groupBy('last_status')
            ->pluck('c', 'last_status');

        return [
            'total' => (int) $counts->sum(),
            'ok' => (int) $counts->get('ok', 0),
            'error' => (int) $counts->get('error', 0),
            'never' => (int) $counts->get('never', 0),
        ];
    }

    /**
     * @return array<string, int>
     */
    private function eventSeverityBreakdown(Site $site, Carbon $since): array
    {
        $counts = Event::query()
            ->where('site_id', $site->id)
            ->where('occurred_at', '>=', $since)
            ->selectRaw('severity, count(*) as c')
            ->groupBy('severity')
            ->pluck('c', 'severity');

        return [
            'total' => (int) $counts->sum(),
            'critical' => (int) $counts->get('critical', 0),
            'warning' => (int) $counts->get('warning', 0),
            'info' => (int) $counts->get('info', 0),
            'none' => (int) $counts->get('', 0) + (int) $counts->get(null, 0),
        ];
    }
}
