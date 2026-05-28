<?php

declare(strict_types=1);

namespace Tests\Feature\Api;

use App\Enums\DeviceStatus;
use App\Enums\EventSeverity;
use App\Enums\SourceStatus;
use App\Models\Device;
use App\Models\Event;
use App\Models\Site;
use App\Models\Source;
use App\Models\Tenant;
use App\Models\User;
use App\Support\CurrentTenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Tests for the site dashboard aggregation endpoints (Sprint 3.2):
 *   GET /api/v1/sites/{site}/summary
 *   GET /api/v1/sites/{site}/timeline
 */
class SiteDashboardTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    private Site $site;

    private Source $source;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();
        $this->tenant = Tenant::create(['slug' => 'acme', 'name' => 'Acme']);
        CurrentTenant::set($this->tenant);
        $this->site = Site::factory()->create();
        $this->source = Source::factory()->forSite($this->site)->create(['last_status' => SourceStatus::Ok]);
        $this->user = User::factory()->inTenant($this->tenant)->create();
    }

    protected function tearDown(): void
    {
        CurrentTenant::forget();
        parent::tearDown();
    }

    public function test_summary_requires_auth(): void
    {
        $this->getJson("/api/v1/sites/{$this->site->id}/summary")->assertStatus(401);
    }

    public function test_summary_reports_device_and_event_breakdown(): void
    {
        Device::factory()->forSource($this->source)->status(DeviceStatus::Online)->count(3)->create();
        Device::factory()->forSource($this->source)->status(DeviceStatus::Offline)->count(2)->create();

        $device = Device::factory()->forSource($this->source)->create();
        Event::factory()->forDevice($device)->severity(EventSeverity::Critical)->count(1)->create();
        Event::factory()->forDevice($device)->severity(EventSeverity::Warning)->count(4)->create();
        Event::factory()->forDevice($device)->severity(EventSeverity::Info)->count(10)->create();

        Sanctum::actingAs($this->user);
        $response = $this->getJson("/api/v1/sites/{$this->site->id}/summary")->assertOk();

        // 3 online + 2 offline + 1 (the event-bearing device) = 6
        $response->assertJsonPath('devices.total', 6)
            ->assertJsonPath('devices.online', 4) // 3 + the default-online factory device
            ->assertJsonPath('devices.offline', 2)
            ->assertJsonPath('sources.ok', 1)
            ->assertJsonPath('events_24h.critical', 1)
            ->assertJsonPath('events_24h.warning', 4)
            ->assertJsonPath('events_24h.info', 10)
            ->assertJsonPath('events_24h.total', 15);

        // recent_events only includes critical + warning (5), capped at 10
        $this->assertCount(5, $response->json('recent_events'));
    }

    public function test_summary_is_tenant_scoped(): void
    {
        // Another tenant's site must 404 for this user.
        $other = Tenant::create(['slug' => 'other', 'name' => 'Other']);
        CurrentTenant::set($other);
        $otherSite = Site::factory()->create();
        CurrentTenant::set($this->tenant);

        Sanctum::actingAs($this->user);
        $this->getJson("/api/v1/sites/{$otherSite->id}/summary")->assertStatus(404);
    }

    public function test_timeline_returns_contiguous_hourly_buckets(): void
    {
        $device = Device::factory()->forSource($this->source)->create();
        // Two events in the current hour.
        Event::factory()->forDevice($device)->severity(EventSeverity::Critical)
            ->create(['occurred_at' => now()]);
        Event::factory()->forDevice($device)->severity(EventSeverity::Info)
            ->create(['occurred_at' => now()]);

        Sanctum::actingAs($this->user);
        $response = $this->getJson("/api/v1/sites/{$this->site->id}/timeline?hours=6")->assertOk();

        $response->assertJsonPath('bucket', 'hour');
        $buckets = $response->json('buckets');

        // 6 contiguous hourly buckets seeded (even empty ones).
        $this->assertGreaterThanOrEqual(6, count($buckets));

        // The last bucket (current hour) holds the two events.
        $last = end($buckets);
        $this->assertSame(2, $last['total']);
        $this->assertSame(1, $last['critical']);
        $this->assertSame(1, $last['info']);
    }

    public function test_timeline_hours_param_is_clamped(): void
    {
        Sanctum::actingAs($this->user);
        // 9999 hours clamps to 168 (7 days) -> at most 169 hourly buckets
        // (168h window + startOfHour rounding includes the partial current hour).
        $response = $this->getJson("/api/v1/sites/{$this->site->id}/timeline?hours=9999")->assertOk();
        $this->assertLessThanOrEqual(169, count($response->json('buckets')));
    }
}
