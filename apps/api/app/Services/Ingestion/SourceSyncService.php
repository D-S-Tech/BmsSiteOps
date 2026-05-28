<?php

declare(strict_types=1);

namespace App\Services\Ingestion;

use App\Enums\SourceStatus;
use App\Models\Device;
use App\Models\Event;
use App\Models\Source;
use App\Support\CurrentTenant;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Applies a worker sync payload to the registry, atomically.
 *
 * A "sync" is the result of one poll cycle for one source: the source's
 * health status, the full/partial set of discovered devices, and any new
 * events. Everything is applied in a single transaction so a partial poll
 * never leaves the registry inconsistent.
 *
 * Tenant context is established from the source itself — the internal API has
 * no authenticated user, so the source's tenant_id is the authority.
 */
class SourceSyncService
{
    /**
     * @param  array{status?: string, error?: string|null, devices?: array<int, array<string, mixed>>, events?: array<int, array<string, mixed>>}  $payload
     * @return array{devices_synced: int, events_ingested: int}
     */
    public function sync(Source $source, array $payload): array
    {
        return DB::transaction(function () use ($source, $payload): array {
            // The source is loaded without tenant scope by the controller;
            // establish the tenant context now so all writes below are scoped.
            CurrentTenant::set($source->tenant_id);

            $deviceMap = $this->upsertDevices($source, $payload['devices'] ?? []);
            $eventCount = $this->insertEvents($source, $deviceMap, $payload['events'] ?? []);
            $this->recordPollResult($source, $payload);

            return [
                'devices_synced' => count($deviceMap),
                'events_ingested' => $eventCount,
            ];
        });
    }

    /**
     * Upsert devices matched on (source_id, external_id).
     *
     * @param  array<int, array<string, mixed>>  $devices
     * @return array<string, int> map of external_id => device_id
     */
    private function upsertDevices(Source $source, array $devices): array
    {
        $map = [];

        foreach ($devices as $payload) {
            $device = Device::updateOrCreate(
                [
                    'source_id' => $source->id,
                    'external_id' => $payload['external_id'],
                ],
                [
                    'site_id' => $source->site_id,
                    'name' => $payload['name'],
                    'type' => $payload['type'] ?? null,
                    'status' => $payload['status'] ?? 'unknown',
                    'last_seen_at' => isset($payload['last_seen_at'])
                        ? Carbon::parse($payload['last_seen_at'])
                        : now(),
                    'metadata' => $payload['metadata'] ?? [],
                ]
            );

            $map[$device->external_id] = $device->id;
        }

        return $map;
    }

    /**
     * Insert events, resolving device_external_id to a device_id.
     *
     * Events referencing an unknown device (not in this payload and not already
     * registered for this source) are skipped — the worker should always send
     * the device in the same sync.
     *
     * @param  array<string, int>  $deviceMap
     * @param  array<int, array<string, mixed>>  $events
     */
    private function insertEvents(Source $source, array $deviceMap, array $events): int
    {
        $count = 0;

        foreach ($events as $payload) {
            $externalId = $payload['device_external_id'];

            $deviceId = $deviceMap[$externalId]
                ?? Device::where('source_id', $source->id)
                    ->where('external_id', $externalId)
                    ->value('id');

            if ($deviceId === null) {
                // Unknown device — skip rather than orphan the event.
                continue;
            }

            Event::create([
                'device_id' => $deviceId,
                'source_id' => $source->id,
                'site_id' => $source->site_id,
                'kind' => $source->kind,
                'metric' => $payload['metric'],
                'value' => isset($payload['value']) ? (string) $payload['value'] : null,
                'severity' => $payload['severity'] ?? null,
                'occurred_at' => Carbon::parse($payload['occurred_at']),
                'metadata' => $payload['metadata'] ?? [],
            ]);

            $count++;
        }

        return $count;
    }

    /**
     * Record the poll outcome on the source.
     *
     * @param  array{status?: string, error?: string|null}  $payload
     */
    private function recordPollResult(Source $source, array $payload): void
    {
        $status = ($payload['status'] ?? 'ok') === 'error'
            ? SourceStatus::Error
            : SourceStatus::Ok;

        $source->forceFill([
            'last_status' => $status,
            'last_polled_at' => now(),
            'last_error' => $status === SourceStatus::Error ? ($payload['error'] ?? null) : null,
        ])->save();
    }
}
