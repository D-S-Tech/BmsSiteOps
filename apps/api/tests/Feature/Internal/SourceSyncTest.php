<?php

declare(strict_types=1);

namespace Tests\Feature\Internal;

use App\Enums\DeviceStatus;
use App\Enums\EventSeverity;
use App\Enums\SourceKind;
use App\Enums\SourceStatus;
use App\Enums\TriageAction;
use App\Enums\TriageStatus;
use App\Models\Device;
use App\Models\Event;
use App\Models\Site;
use App\Models\Source;
use App\Models\Tenant;
use App\Models\TriageDecision;
use App\Models\TriageRule;
use App\Support\CurrentTenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Tests for the internal worker→API ingestion endpoint:
 *   POST /internal/sources/{source}/sync
 *
 * Covers HMAC authentication (the VerifyWorkerSignature middleware) and the
 * SourceSyncService behavior (device upsert, event insert, source status).
 */
class SourceSyncTest extends TestCase
{
    use RefreshDatabase;

    private string $key = 'test-worker-secret-key';

    private Source $source;

    protected function setUp(): void
    {
        parent::setUp();
        config(['services.worker.internal_key' => $this->key]);

        $tenant = Tenant::create(['slug' => 'acme', 'name' => 'Acme']);
        CurrentTenant::set($tenant);
        $site = Site::factory()->create();
        $this->source = Source::factory()->forSite($site)->kind(SourceKind::Trmm)->create();

        // The endpoint runs without a user session; clear tenant context so the
        // tests prove the service re-establishes it from the source.
        CurrentTenant::forget();
    }

    protected function tearDown(): void
    {
        CurrentTenant::forget();
        parent::tearDown();
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array{0: string, 1: array<string, string>}
     */
    private function sign(array $payload): array
    {
        $body = json_encode($payload, JSON_THROW_ON_ERROR);
        $timestamp = (string) time();
        $signature = hash_hmac('sha256', $timestamp.'.'.$body, $this->key);

        return [$body, [
            'X-Worker-Timestamp' => $timestamp,
            'X-Worker-Signature' => $signature,
            'Content-Type' => 'application/json',
        ]];
    }

    private function url(?int $sourceId = null): string
    {
        return "/internal/sources/{$sourceId}/sync";
    }

    // --- Authentication -------------------------------------------------------

    public function test_request_without_signature_is_rejected(): void
    {
        $this->postJson($this->url($this->source->id), ['status' => 'ok'])
            ->assertStatus(401);
    }

    public function test_request_with_invalid_signature_is_rejected(): void
    {
        [$body, $headers] = $this->sign(['status' => 'ok']);
        $headers['X-Worker-Signature'] = 'deadbeef';

        $this->call('POST', $this->url($this->source->id), [], [], [], $this->transform($headers), $body)
            ->assertStatus(401);
    }

    public function test_request_with_stale_timestamp_is_rejected(): void
    {
        $body = json_encode(['status' => 'ok'], JSON_THROW_ON_ERROR);
        $timestamp = (string) (time() - 9999);
        $signature = hash_hmac('sha256', $timestamp.'.'.$body, $this->key);

        $headers = $this->transform([
            'X-Worker-Timestamp' => $timestamp,
            'X-Worker-Signature' => $signature,
            'Content-Type' => 'application/json',
        ]);

        $this->call('POST', $this->url($this->source->id), [], [], [], $headers, $body)
            ->assertStatus(401);
    }

    public function test_unconfigured_key_returns_503(): void
    {
        config(['services.worker.internal_key' => null]);
        [$body, $headers] = $this->sign(['status' => 'ok']);

        $this->call('POST', $this->url($this->source->id), [], [], [], $this->transform($headers), $body)
            ->assertStatus(503);
    }

    // --- Sync behavior --------------------------------------------------------

    public function test_valid_sync_upserts_devices_and_inserts_events(): void
    {
        $payload = [
            'status' => 'ok',
            'devices' => [
                [
                    'external_id' => 'agent-001',
                    'name' => 'DC-SERVER-01',
                    'type' => 'server',
                    'status' => 'online',
                    'metadata' => ['os' => 'Windows Server 2022'],
                ],
            ],
            'events' => [
                [
                    'device_external_id' => 'agent-001',
                    'metric' => 'alert',
                    'value' => 'Disk space low',
                    'severity' => 'warning',
                    'occurred_at' => now()->toIso8601String(),
                ],
            ],
        ];

        [$body, $headers] = $this->sign($payload);

        $response = $this->call('POST', $this->url($this->source->id), [], [], [], $this->transform($headers), $body);

        $response->assertOk()
            ->assertJson([
                'source_id' => $this->source->id,
                'devices_synced' => 1,
                'events_ingested' => 1,
            ]);

        CurrentTenant::set($this->source->tenant_id);
        $this->assertSame(1, Device::count());
        $this->assertSame(1, Event::count());

        $device = Device::first();
        $this->assertSame('agent-001', $device->external_id);
        $this->assertSame($this->source->site_id, $device->site_id);
        $this->assertSame(DeviceStatus::Online, $device->status);

        $event = Event::first();
        $this->assertSame($device->id, $event->device_id);
        $this->assertSame(EventSeverity::Warning, $event->severity);
        $this->assertSame(SourceKind::Trmm, $event->kind);
    }

    public function test_repeated_sync_updates_existing_device_not_duplicate(): void
    {
        $first = [
            'devices' => [[
                'external_id' => 'agent-001', 'name' => 'OLD-NAME', 'status' => 'online',
            ]],
        ];
        [$body, $headers] = $this->sign($first);
        $this->call('POST', $this->url($this->source->id), [], [], [], $this->transform($headers), $body)->assertOk();

        $second = [
            'devices' => [[
                'external_id' => 'agent-001', 'name' => 'NEW-NAME', 'status' => 'offline',
            ]],
        ];
        [$body, $headers] = $this->sign($second);
        $this->call('POST', $this->url($this->source->id), [], [], [], $this->transform($headers), $body)->assertOk();

        CurrentTenant::set($this->source->tenant_id);
        $this->assertSame(1, Device::count());
        $device = Device::first();
        $this->assertSame('NEW-NAME', $device->name);
        $this->assertSame(DeviceStatus::Offline, $device->status);
    }

    public function test_event_for_unknown_device_is_skipped(): void
    {
        $payload = [
            'events' => [[
                'device_external_id' => 'ghost', 'metric' => 'alert',
                'occurred_at' => now()->toIso8601String(),
            ]],
        ];
        [$body, $headers] = $this->sign($payload);

        $this->call('POST', $this->url($this->source->id), [], [], [], $this->transform($headers), $body)
            ->assertOk()
            ->assertJson(['events_ingested' => 0]);

        CurrentTenant::set($this->source->tenant_id);
        $this->assertSame(0, Event::count());
    }

    public function test_sync_updates_source_status_to_ok(): void
    {
        [$body, $headers] = $this->sign(['status' => 'ok', 'devices' => [], 'events' => []]);
        $this->call('POST', $this->url($this->source->id), [], [], [], $this->transform($headers), $body)->assertOk();

        $this->source->refresh();
        $this->assertSame(SourceStatus::Ok, $this->source->last_status);
        $this->assertNotNull($this->source->last_polled_at);
        $this->assertNull($this->source->last_error);
    }

    public function test_sync_records_error_status(): void
    {
        [$body, $headers] = $this->sign(['status' => 'error', 'error' => 'TRMM 401 Unauthorized']);
        $this->call('POST', $this->url($this->source->id), [], [], [], $this->transform($headers), $body)->assertOk();

        $this->source->refresh();
        $this->assertSame(SourceStatus::Error, $this->source->last_status);
        $this->assertSame('TRMM 401 Unauthorized', $this->source->last_error);
    }

    public function test_unknown_source_returns_404(): void
    {
        [$body, $headers] = $this->sign(['status' => 'ok']);
        $this->call('POST', $this->url(999999), [], [], [], $this->transform($headers), $body)
            ->assertStatus(404);
    }

    public function test_sync_runs_triage_on_ingested_events_and_records_decisions(): void
    {
        // A rule scoped to the source's tenant matches critical events.
        CurrentTenant::set($this->source->tenant_id);
        TriageRule::factory()
            ->matching(['severity_match' => 'critical'])
            ->action(TriageAction::MarkForReview)
            ->create();
        CurrentTenant::forget();

        $payload = [
            'devices' => [[
                'external_id' => 'agent-001', 'name' => 'DC-SERVER-01', 'status' => 'online',
            ]],
            'events' => [
                [
                    'device_external_id' => 'agent-001', 'metric' => 'alert', 'value' => 'Disk',
                    'severity' => 'critical', 'occurred_at' => now()->toIso8601String(),
                ],
                [
                    'device_external_id' => 'agent-001', 'metric' => 'heartbeat', 'value' => 'ok',
                    'severity' => 'info', 'occurred_at' => now()->toIso8601String(),
                ],
            ],
        ];

        [$body, $headers] = $this->sign($payload);
        $this->call('POST', $this->url($this->source->id), [], [], [], $this->transform($headers), $body)
            ->assertOk()
            ->assertJson([
                'source_id' => $this->source->id,
                'devices_synced' => 1,
                'events_ingested' => 2,
                'triage_decisions' => 1, // only the critical event matched
            ]);

        CurrentTenant::set($this->source->tenant_id);
        $this->assertSame(1, TriageDecision::count());
        $decision = TriageDecision::first();
        $this->assertSame(TriageAction::MarkForReview, $decision->action);
        $this->assertSame(TriageStatus::Executed, $decision->status);
    }

    /**
     * Convert nice header names to the server-var form Laravel's call() expects.
     *
     * @param  array<string, string>  $headers
     * @return array<string, string>
     */
    private function transform(array $headers): array
    {
        $server = [];
        foreach ($headers as $name => $value) {
            if (strtolower($name) === 'content-type') {
                $server['CONTENT_TYPE'] = $value;

                continue;
            }
            $server['HTTP_'.strtoupper(str_replace('-', '_', $name))] = $value;
        }

        return $server;
    }
}
