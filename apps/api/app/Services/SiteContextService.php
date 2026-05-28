<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\Device;
use App\Models\Event;
use App\Models\Site;
use App\Models\Source;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Carbon;

/**
 * Builds aggregated views of a site's current state.
 *
 * Single source of truth for site rollups, shared by the operator dashboard
 * API (SiteDashboardController) and the internal brief-context endpoint that
 * feeds the worker's AI Site Brief. Everything is tenant-scoped automatically
 * via the models' BelongsToTenant global scope, and every aggregation uses
 * portable SQL (groupBy + count) so it runs identically on PostgreSQL and the
 * SQLite test database; timelines are bucketed in PHP for the same reason.
 */
class SiteContextService
{
    /**
     * Device status breakdown including effectively-muted count.
     *
     * @return array<string, int>
     */
    public function deviceBreakdown(Site $site): array
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
            'muted' => $this->mutedDeviceIds($site)->count(),
        ];
    }

    /**
     * Source health breakdown.
     *
     * @return array<string, int>
     */
    public function sourceBreakdown(Site $site): array
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
     * Event-severity rollup since a given moment.
     *
     * @return array<string, int>
     */
    public function eventSeverityBreakdown(Site $site, Carbon $since): array
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

    /**
     * Most recent critical/warning events, excluding muted devices.
     *
     * @return Collection<int, Event>
     */
    public function recentActionableEvents(Site $site, int $limit = 10): Collection
    {
        return Event::query()
            ->where('site_id', $site->id)
            ->whereIn('severity', ['critical', 'warning'])
            ->whereNotIn('device_id', $this->mutedDeviceIds($site))
            ->orderByDesc('occurred_at')
            ->limit($limit)
            ->get();
    }

    /**
     * Contiguous hourly event-count buckets (zero-seeded) by severity.
     *
     * @return array{bucket: string, from: string, to: string, buckets: list<array<string, mixed>>}
     */
    public function hourlyTimeline(Site $site, int $hours = 24): array
    {
        $hours = max(1, min(168, $hours));
        $from = now()->subHours($hours)->startOfHour();
        $to = now();

        $events = Event::query()
            ->where('site_id', $site->id)
            ->where('occurred_at', '>=', $from)
            ->orderBy('occurred_at')
            ->get(['occurred_at', 'severity']);

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

        return [
            'bucket' => 'hour',
            'from' => $from->toIso8601String(),
            'to' => $to->toIso8601String(),
            'buckets' => array_values($buckets),
        ];
    }

    /**
     * Full context payload for AI Site Brief generation (worker-facing).
     *
     * @return array<string, mixed>
     */
    public function briefContext(Site $site, int $hours = 24): array
    {
        $since = now()->subHours($hours);

        return [
            'site' => [
                'id' => $site->id,
                'name' => $site->name,
                'address' => $site->address,
            ],
            'period' => [
                'start' => $since->toIso8601String(),
                'end' => now()->toIso8601String(),
                'hours' => $hours,
            ],
            'devices' => $this->deviceBreakdown($site),
            'sources' => $this->sourceBreakdown($site),
            'events' => $this->eventSeverityBreakdown($site, $since),
            'timeline' => $this->hourlyTimeline($site, $hours)['buckets'],
            'recent_events' => $this->recentActionableEvents($site)
                ->map(fn (Event $e): array => [
                    'metric' => $e->metric,
                    'value' => $e->value,
                    'severity' => $e->severity?->value,
                    'occurred_at' => $e->occurred_at->toIso8601String(),
                ])
                ->all(),
        ];
    }

    /**
     * IDs of devices that are effectively muted at this site.
     *
     * @return \Illuminate\Support\Collection<int, int>
     */
    private function mutedDeviceIds(Site $site): \Illuminate\Support\Collection
    {
        return Device::query()
            ->where('site_id', $site->id)
            ->effectivelyMuted()
            ->pluck('id');
    }
}
