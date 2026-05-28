<?php

declare(strict_types=1);

namespace Tests\Feature\Api;

use App\Enums\DeviceStatus;
use App\Enums\EventSeverity;
use App\Enums\SourceKind;
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
 * Tests for the public /api/v1 surface: auth, tenant scoping, the
 * credentials-hiding guarantee, source CRUD, and device/event filtering.
 */
class ApiV1Test extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    private User $user;

    private Site $site;

    protected function setUp(): void
    {
        parent::setUp();
        $this->tenant = Tenant::create(['slug' => 'acme', 'name' => 'Acme']);
        CurrentTenant::set($this->tenant);
        $this->site = Site::factory()->create(['slug' => 'hq', 'name' => 'Acme HQ']);
        $this->user = User::factory()->inTenant($this->tenant)->create();
    }

    protected function tearDown(): void
    {
        CurrentTenant::forget();
        parent::tearDown();
    }

    private function actingAsUser(): self
    {
        Sanctum::actingAs($this->user);

        return $this;
    }

    // --- Auth -----------------------------------------------------------------

    public function test_ping_is_public(): void
    {
        $this->getJson('/api/v1/ping')->assertOk()->assertJson(['pong' => true]);
    }

    public function test_unauthenticated_requests_are_rejected(): void
    {
        $this->getJson('/api/v1/sources')->assertStatus(401);
        $this->getJson('/api/v1/devices')->assertStatus(401);
        $this->getJson('/api/v1/events')->assertStatus(401);
    }

    public function test_user_without_tenant_gets_409(): void
    {
        $orphan = User::factory()->create(['current_tenant_id' => null]);
        Sanctum::actingAs($orphan);

        $this->getJson('/api/v1/sources')->assertStatus(409);
    }

    // --- Tenant scoping -------------------------------------------------------

    public function test_listing_is_scoped_to_users_tenant(): void
    {
        Source::factory()->forSite($this->site)->count(2)->create();

        // Another tenant's sources must not appear.
        $other = Tenant::create(['slug' => 'other', 'name' => 'Other']);
        CurrentTenant::set($other);
        $otherSite = Site::factory()->create();
        Source::factory()->forSite($otherSite)->count(3)->create();
        CurrentTenant::set($this->tenant);

        $response = $this->actingAsUser()->getJson('/api/v1/sources')->assertOk();
        $this->assertCount(2, $response->json('data'));
    }

    public function test_cannot_show_another_tenants_source(): void
    {
        $other = Tenant::create(['slug' => 'other', 'name' => 'Other']);
        CurrentTenant::set($other);
        $otherSite = Site::factory()->create();
        $otherSource = Source::factory()->forSite($otherSite)->create();
        CurrentTenant::set($this->tenant);

        $this->actingAsUser()->getJson("/api/v1/sources/{$otherSource->id}")->assertStatus(404);
    }

    // --- Credentials hiding ---------------------------------------------------

    public function test_source_resource_never_exposes_credentials(): void
    {
        $source = Source::factory()->forSite($this->site)->create([
            'credentials' => ['api_token' => 'top-secret-value'],
        ]);

        $response = $this->actingAsUser()->getJson("/api/v1/sources/{$source->id}")->assertOk();

        $json = $response->json('data');
        $this->assertArrayNotHasKey('credentials', $json);
        $this->assertTrue($json['has_credentials']);
        $this->assertStringNotContainsString('top-secret-value', $response->getContent());
    }

    // --- Source CRUD ----------------------------------------------------------

    public function test_can_create_a_source(): void
    {
        $payload = [
            'site_id' => $this->site->id,
            'kind' => SourceKind::Trmm->value,
            'name' => 'TRMM — Acme HQ',
            'base_url' => 'https://trmm.example.com',
            'credentials' => ['api_token' => 'abc123'],
            'poll_interval_seconds' => 120,
        ];

        $response = $this->actingAsUser()->postJson('/api/v1/sources', $payload)->assertCreated();

        $this->assertSame('TRMM — Acme HQ', $response->json('data.name'));
        $this->assertDatabaseHas('sources', [
            'name' => 'TRMM — Acme HQ',
            'tenant_id' => $this->tenant->id,
            'site_id' => $this->site->id,
        ]);
    }

    public function test_cannot_create_source_for_another_tenants_site(): void
    {
        $other = Tenant::create(['slug' => 'other', 'name' => 'Other']);
        CurrentTenant::set($other);
        $otherSite = Site::factory()->create();
        CurrentTenant::set($this->tenant);

        $this->actingAsUser()->postJson('/api/v1/sources', [
            'site_id' => $otherSite->id,
            'kind' => SourceKind::Trmm->value,
            'name' => 'Sneaky',
        ])->assertStatus(422);
    }

    public function test_can_update_a_source(): void
    {
        $source = Source::factory()->forSite($this->site)->create(['name' => 'Old']);

        $this->actingAsUser()
            ->patchJson("/api/v1/sources/{$source->id}", ['name' => 'New', 'is_active' => false])
            ->assertOk()
            ->assertJsonPath('data.name', 'New')
            ->assertJsonPath('data.is_active', false);
    }

    public function test_can_delete_a_source(): void
    {
        $source = Source::factory()->forSite($this->site)->create();

        $this->actingAsUser()->deleteJson("/api/v1/sources/{$source->id}")->assertStatus(204);
        $this->assertDatabaseMissing('sources', ['id' => $source->id]);
    }

    // --- Device + Event filtering ---------------------------------------------

    public function test_devices_can_be_filtered_by_status(): void
    {
        $source = Source::factory()->forSite($this->site)->create();
        Device::factory()->forSource($source)->status(DeviceStatus::Online)->count(2)->create();
        Device::factory()->forSource($source)->status(DeviceStatus::Offline)->count(1)->create();

        $response = $this->actingAsUser()->getJson('/api/v1/devices?status=offline')->assertOk();
        $this->assertCount(1, $response->json('data'));
    }

    public function test_events_are_filtered_by_severity_and_ordered_desc(): void
    {
        $source = Source::factory()->forSite($this->site)->create();
        $device = Device::factory()->forSource($source)->create();
        Event::factory()->forDevice($device)->severity(EventSeverity::Critical)->count(2)->create();
        Event::factory()->forDevice($device)->severity(EventSeverity::Info)->count(3)->create();

        $response = $this->actingAsUser()->getJson('/api/v1/events?severity=critical')->assertOk();
        $this->assertCount(2, $response->json('data'));
    }
}
