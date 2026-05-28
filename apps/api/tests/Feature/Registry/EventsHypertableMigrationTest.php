<?php

declare(strict_types=1);

namespace Tests\Feature\Registry;

use App\Models\Device;
use App\Models\Event;
use App\Models\Site;
use App\Models\Source;
use App\Models\Tenant;
use App\Support\CurrentTenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * Verifies the TimescaleDB hypertable migration is a safe no-op on non-pgsql
 * connections (the SQLite test database), so events remains a fully working
 * ordinary table in CI and local dev.
 *
 * The actual TimescaleDB conversion is validated against a real TimescaleDB
 * instance, not here — see ADR 0008.
 */
class EventsHypertableMigrationTest extends TestCase
{
    use RefreshDatabase;

    public function test_tests_run_on_sqlite(): void
    {
        // Guards the assumption these tests rely on.
        $this->assertSame('sqlite', DB::getDriverName());
    }

    public function test_events_table_exists_and_is_queryable_after_migrations(): void
    {
        $this->assertTrue(Schema::hasTable('events'));

        $tenant = Tenant::create(['slug' => 'acme', 'name' => 'Acme']);
        CurrentTenant::set($tenant);
        $site = Site::factory()->create();
        $source = Source::factory()->forSite($site)->create();
        $device = Device::factory()->forSource($source)->create();
        Event::factory()->forDevice($device)->count(3)->create();

        $this->assertSame(3, Event::count());

        CurrentTenant::forget();
    }
}
