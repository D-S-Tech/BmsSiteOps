<?php

declare(strict_types=1);

namespace Tests\Feature\Registry;

use App\Enums\DeviceStatus;
use App\Enums\EventSeverity;
use App\Enums\SourceKind;
use App\Enums\SourceStatus;
use App\Models\Device;
use App\Models\Event;
use App\Models\Scopes\TenantScope;
use App\Models\Site;
use App\Models\Source;
use App\Models\Tenant;
use App\Support\CurrentTenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Data-model tests for the Sprint 1 registry: Source, Device, Event.
 *
 * Covers tenant isolation (the same invariants as the canonical
 * TenantScopeTest), relationship wiring, denormalized-column consistency,
 * encrypted credentials at rest, and the append-only nature of events.
 */
class RegistryTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    private Site $site;

    protected function setUp(): void
    {
        parent::setUp();
        $this->tenant = Tenant::create(['slug' => 'acme', 'name' => 'Acme Inc']);
        CurrentTenant::set($this->tenant);
        $this->site = Site::factory()->create(['slug' => 'hq', 'name' => 'Acme HQ']);
    }

    protected function tearDown(): void
    {
        CurrentTenant::forget();
        parent::tearDown();
    }

    // --- Tenant isolation -----------------------------------------------------

    public function test_source_is_scoped_to_current_tenant(): void
    {
        $other = Tenant::create(['slug' => 'other', 'name' => 'Other']);

        Source::factory()->forSite($this->site)->count(2)->create();

        CurrentTenant::set($other);
        $otherSite = Site::factory()->create();
        Source::factory()->forSite($otherSite)->count(3)->create();

        $this->assertSame(3, Source::count());

        CurrentTenant::set($this->tenant);
        $this->assertSame(2, Source::count());
    }

    public function test_device_and_event_are_scoped_to_current_tenant(): void
    {
        $source = Source::factory()->forSite($this->site)->create();
        $device = Device::factory()->forSource($source)->create();
        Event::factory()->forDevice($device)->count(5)->create();

        $this->assertSame(1, Device::count());
        $this->assertSame(5, Event::count());

        CurrentTenant::forget();
        $this->assertSame(0, Device::count());
        $this->assertSame(0, Event::count());
    }

    public function test_super_admin_can_bypass_scope(): void
    {
        $source = Source::factory()->forSite($this->site)->create();
        Device::factory()->forSource($source)->count(2)->create();

        $other = Tenant::create(['slug' => 'other', 'name' => 'Other']);
        CurrentTenant::set($other);
        $otherSite = Site::factory()->create();
        $otherSource = Source::factory()->forSite($otherSite)->create();
        Device::factory()->forSource($otherSource)->count(3)->create();

        CurrentTenant::forget();
        $all = Device::withoutGlobalScope(TenantScope::class)->get();
        $this->assertCount(5, $all);
    }

    // --- Relationships --------------------------------------------------------

    public function test_site_has_sources_devices_and_events(): void
    {
        $source = Source::factory()->forSite($this->site)->create();
        $device = Device::factory()->forSource($source)->create();
        Event::factory()->forDevice($device)->count(4)->create();

        $this->assertTrue($this->site->sources()->exists());
        $this->assertSame(1, $this->site->devices()->count());
        $this->assertSame(4, $this->site->events()->count());
    }

    public function test_source_has_devices_and_events(): void
    {
        $source = Source::factory()->forSite($this->site)->create();
        $device = Device::factory()->forSource($source)->create();
        Event::factory()->forDevice($device)->count(2)->create();

        $this->assertSame(1, $source->devices()->count());
        $this->assertSame(2, $source->events()->count());
    }

    public function test_device_belongs_to_source_and_site_and_has_events(): void
    {
        $source = Source::factory()->forSite($this->site)->create();
        $device = Device::factory()->forSource($source)->create();
        Event::factory()->forDevice($device)->count(3)->create();

        $this->assertSame($source->id, $device->source->id);
        $this->assertSame($this->site->id, $device->site->id);
        $this->assertSame(3, $device->events()->count());
    }

    // --- Denormalized-column consistency --------------------------------------

    public function test_device_site_id_matches_its_source(): void
    {
        $source = Source::factory()->forSite($this->site)->create();
        $device = Device::factory()->forSource($source)->create();

        $this->assertSame($source->site_id, $device->site_id);
        $this->assertSame($this->site->id, $device->site_id);
    }

    public function test_event_denormalized_columns_match_its_device(): void
    {
        $source = Source::factory()->forSite($this->site)->kind(SourceKind::Trmm)->create();
        $device = Device::factory()->forSource($source)->create();
        $event = Event::factory()->forDevice($device)->create();

        $this->assertSame($device->id, $event->device_id);
        $this->assertSame($source->id, $event->source_id);
        $this->assertSame($this->site->id, $event->site_id);
        $this->assertSame(SourceKind::Trmm, $event->kind);
    }

    // --- Casts + behavior -----------------------------------------------------

    public function test_credentials_are_encrypted_at_rest(): void
    {
        $source = Source::factory()->forSite($this->site)->create([
            'credentials' => ['api_token' => 'super-secret-token'],
        ]);

        // The model accessor decrypts transparently.
        $this->assertSame('super-secret-token', $source->credentials['api_token']);

        // The raw database value must NOT contain the plaintext.
        $raw = \DB::table('sources')->where('id', $source->id)->value('credentials');
        $this->assertIsString($raw);
        $this->assertStringNotContainsString('super-secret-token', $raw);
    }

    public function test_enums_are_cast(): void
    {
        $source = Source::factory()->forSite($this->site)->kind(SourceKind::Niagara)->create();
        $device = Device::factory()->forSource($source)
            ->status(DeviceStatus::Offline)->create();
        $event = Event::factory()->forDevice($device)
            ->severity(EventSeverity::Critical)->create();

        $this->assertInstanceOf(SourceKind::class, $source->kind);
        $this->assertSame(SourceStatus::Never, $source->last_status);
        $this->assertInstanceOf(DeviceStatus::class, $device->status);
        $this->assertSame(DeviceStatus::Offline, $device->status);
        $this->assertSame(EventSeverity::Critical, $event->severity);
    }

    public function test_events_are_append_only_without_updated_at(): void
    {
        $source = Source::factory()->forSite($this->site)->create();
        $device = Device::factory()->forSource($source)->create();
        $event = Event::factory()->forDevice($device)->create();

        // UPDATED_AT is disabled on the Event model.
        $this->assertNull(Event::UPDATED_AT);
        $this->assertArrayNotHasKey('updated_at', $event->getAttributes());
    }
}
