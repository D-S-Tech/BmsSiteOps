<?php

declare(strict_types=1);

namespace Tests\Feature\Api;

use App\Enums\EventSeverity;
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
 * Tests for the device muting operator workflow (Sprint 3.4).
 */
class DeviceMuteTest extends TestCase
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
        $this->source = Source::factory()->forSite($this->site)->create();
        $this->user = User::factory()->inTenant($this->tenant)->create();
    }

    protected function tearDown(): void
    {
        CurrentTenant::forget();
        parent::tearDown();
    }

    public function test_mute_requires_auth(): void
    {
        $device = Device::factory()->forSource($this->source)->create();
        $this->postJson("/api/v1/devices/{$device->id}/mute")->assertStatus(401);
    }

    public function test_mute_indefinitely(): void
    {
        $device = Device::factory()->forSource($this->source)->create();

        Sanctum::actingAs($this->user);
        $this->postJson("/api/v1/devices/{$device->id}/mute")
            ->assertOk()
            ->assertJsonPath('data.is_muted', true)
            ->assertJsonPath('data.muted_until', null)
            ->assertJsonPath('data.effectively_muted', true);

        $this->assertTrue($device->fresh()->is_muted);
    }

    public function test_mute_with_until(): void
    {
        $device = Device::factory()->forSource($this->source)->create();
        $until = now()->addHours(2)->toIso8601String();

        Sanctum::actingAs($this->user);
        $this->postJson("/api/v1/devices/{$device->id}/mute", ['until' => $until])
            ->assertOk()
            ->assertJsonPath('data.is_muted', true)
            ->assertJsonPath('data.effectively_muted', true);

        $this->assertNotNull($device->fresh()->muted_until);
    }

    public function test_mute_rejects_past_until(): void
    {
        $device = Device::factory()->forSource($this->source)->create();

        Sanctum::actingAs($this->user);
        $this->postJson("/api/v1/devices/{$device->id}/mute", [
            'until' => now()->subHour()->toIso8601String(),
        ])->assertStatus(422)->assertJsonValidationErrors('until');
    }

    public function test_expired_timed_mute_is_not_effectively_muted(): void
    {
        // is_muted true but muted_until in the past -> not effectively muted.
        $device = Device::factory()->forSource($this->source)->create([
            'is_muted' => true,
            'muted_until' => now()->subHour(),
        ]);

        Sanctum::actingAs($this->user);
        $this->getJson("/api/v1/devices/{$device->id}")
            ->assertOk()
            ->assertJsonPath('data.is_muted', true)
            ->assertJsonPath('data.effectively_muted', false);
    }

    public function test_unmute(): void
    {
        $device = Device::factory()->forSource($this->source)->create([
            'is_muted' => true,
            'muted_until' => now()->addHour(),
        ]);

        Sanctum::actingAs($this->user);
        $this->postJson("/api/v1/devices/{$device->id}/unmute")
            ->assertOk()
            ->assertJsonPath('data.is_muted', false)
            ->assertJsonPath('data.muted_until', null)
            ->assertJsonPath('data.effectively_muted', false);
    }

    public function test_muted_device_events_excluded_from_site_summary(): void
    {
        $loud = Device::factory()->forSource($this->source)->create();
        $muted = Device::factory()->forSource($this->source)->create([
            'is_muted' => true,
            'muted_until' => null,
        ]);

        Event::factory()->forDevice($loud)->severity(EventSeverity::Critical)->count(2)->create();
        Event::factory()->forDevice($muted)->severity(EventSeverity::Critical)->count(3)->create();

        Sanctum::actingAs($this->user);
        $response = $this->getJson("/api/v1/sites/{$this->site->id}/summary")->assertOk();

        // Only the 2 events from the un-muted device appear in recent_events.
        $this->assertCount(2, $response->json('recent_events'));
        // The muted device is counted in the muted breakdown.
        $response->assertJsonPath('devices.muted', 1);
    }
}
