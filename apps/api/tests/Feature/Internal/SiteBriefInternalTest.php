<?php

declare(strict_types=1);

namespace Tests\Feature\Internal;

use App\Enums\EventSeverity;
use App\Models\Device;
use App\Models\Event;
use App\Models\Site;
use App\Models\SiteBrief;
use App\Models\Source;
use App\Models\Tenant;
use App\Support\CurrentTenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Tests for the internal worker→API AI Site Brief endpoints (Sprint 4.1):
 *   GET  /internal/sites/{site}/brief-context
 *   POST /internal/sites/{site}/briefs
 *
 * Both are HMAC-authenticated (VerifyWorkerSignature) and carry no user
 * session — the tenant is resolved from the site.
 */
class SiteBriefInternalTest extends TestCase
{
    use RefreshDatabase;

    private string $key = 'test-worker-secret-key';

    private Site $site;

    private Source $source;

    protected function setUp(): void
    {
        parent::setUp();
        config(['services.worker.internal_key' => $this->key]);

        $tenant = Tenant::create(['slug' => 'acme', 'name' => 'Acme']);
        CurrentTenant::set($tenant);
        $this->site = Site::factory()->create();
        $this->source = Source::factory()->forSite($this->site)->create();

        // Prove the endpoints re-establish tenant context from the site.
        CurrentTenant::forget();
    }

    protected function tearDown(): void
    {
        CurrentTenant::forget();
        parent::tearDown();
    }

    // --- brief-context (GET) --------------------------------------------------

    public function test_brief_context_requires_signature(): void
    {
        $this->getJson("/internal/sites/{$this->site->id}/brief-context")->assertStatus(401);
    }

    public function test_brief_context_returns_site_rollup(): void
    {
        CurrentTenant::set($this->source->tenant_id);
        $device = Device::factory()->forSource($this->source)->create();
        Event::factory()->forDevice($device)->severity(EventSeverity::Critical)->count(2)->create();
        CurrentTenant::forget();

        [$server, $body] = $this->signGet();
        $response = $this->call(
            'GET',
            "/internal/sites/{$this->site->id}/brief-context",
            [],
            [],
            [],
            $server,
            $body
        );

        $response->assertOk()
            ->assertJsonStructure([
                'site' => ['id', 'name'],
                'period' => ['start', 'end', 'hours'],
                'devices' => ['total', 'online', 'muted'],
                'sources' => ['total', 'ok'],
                'events' => ['total', 'critical'],
                'timeline',
                'recent_events',
            ])
            ->assertJsonPath('site.id', $this->site->id)
            ->assertJsonPath('events.critical', 2);
    }

    // --- store brief (POST) ---------------------------------------------------

    public function test_store_brief_requires_signature(): void
    {
        $this->postJson("/internal/sites/{$this->site->id}/briefs", ['summary' => 'x'])
            ->assertStatus(401);
    }

    public function test_store_brief_persists_with_tenant_from_site(): void
    {
        $payload = [
            'summary' => 'All systems nominal. Two critical disk alerts on DC-SERVER-01.',
            'model' => 'claude-sonnet-4-5',
            'period_start' => now()->subDay()->toIso8601String(),
            'period_end' => now()->toIso8601String(),
            'generated_at' => now()->toIso8601String(),
            'metadata' => ['input_tokens' => 1200, 'output_tokens' => 180],
        ];

        [$server, $body] = $this->signPost($payload);
        $response = $this->call(
            'POST',
            "/internal/sites/{$this->site->id}/briefs",
            [],
            [],
            [],
            $server,
            $body
        );

        $response->assertCreated()
            ->assertJsonPath('data.model', 'claude-sonnet-4-5')
            ->assertJsonPath('data.site_id', $this->site->id);

        CurrentTenant::set($this->source->tenant_id);
        $this->assertSame(1, SiteBrief::count());
        $brief = SiteBrief::first();
        $this->assertSame($this->site->tenant_id, $brief->tenant_id);
        $this->assertSame(1200, $brief->metadata['input_tokens']);
    }

    public function test_store_brief_validates_payload(): void
    {
        [$server, $body] = $this->signPost(['model' => 'x']); // missing required fields
        $this->call('POST', "/internal/sites/{$this->site->id}/briefs", [], [], [], $server, $body)
            ->assertStatus(422);
    }

    public function test_store_brief_unknown_site_returns_404(): void
    {
        $payload = [
            'summary' => 'x',
            'model' => 'm',
            'period_start' => now()->subDay()->toIso8601String(),
            'period_end' => now()->toIso8601String(),
            'generated_at' => now()->toIso8601String(),
        ];
        [$server, $body] = $this->signPost($payload);
        $this->call('POST', '/internal/sites/999999/briefs', [], [], [], $server, $body)
            ->assertStatus(404);
    }

    // --- signing helpers ------------------------------------------------------

    /**
     * @return array{0: array<string, string>, 1: string}
     */
    private function signGet(): array
    {
        $body = '';
        $timestamp = (string) time();
        $signature = hash_hmac('sha256', $timestamp.'.'.$body, $this->key);

        return [$this->server($timestamp, $signature), $body];
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array{0: array<string, string>, 1: string}
     */
    private function signPost(array $payload): array
    {
        $body = json_encode($payload, JSON_THROW_ON_ERROR);
        $timestamp = (string) time();
        $signature = hash_hmac('sha256', $timestamp.'.'.$body, $this->key);

        return [$this->server($timestamp, $signature), $body];
    }

    /**
     * @return array<string, string>
     */
    private function server(string $timestamp, string $signature): array
    {
        return [
            'HTTP_X_WORKER_TIMESTAMP' => $timestamp,
            'HTTP_X_WORKER_SIGNATURE' => $signature,
            'CONTENT_TYPE' => 'application/json',
            'HTTP_ACCEPT' => 'application/json',
        ];
    }
}
