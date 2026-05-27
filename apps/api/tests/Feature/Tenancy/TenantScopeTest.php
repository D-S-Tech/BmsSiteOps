<?php

declare(strict_types=1);

namespace Tests\Feature\Tenancy;

use App\Exceptions\NoTenantInScopeException;
use App\Models\Scopes\TenantScope;
use App\Models\Site;
use App\Models\Tenant;
use App\Support\CurrentTenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Core multi-tenancy isolation tests.
 *
 * Every tenant-scoped model must pass equivalent tests. As more models are
 * added (sources, devices, events, ...), reuse the assertions below either
 * by parameterizing this test or by adding model-specific test files that
 * include the same invariants.
 *
 * See: docs/adr/0002-multi-tenancy-row-level.md
 */
class TenantScopeTest extends TestCase
{
    use RefreshDatabase;

    protected function tearDown(): void
    {
        CurrentTenant::forget();
        parent::tearDown();
    }

    public function test_creating_a_tenant_scoped_model_without_tenant_in_scope_throws(): void
    {
        $this->expectException(NoTenantInScopeException::class);

        Site::create([
            'slug' => 'test-site',
            'name' => 'Test Site',
        ]);
    }

    public function test_creating_a_tenant_scoped_model_sets_tenant_id_from_current_tenant(): void
    {
        $tenant = Tenant::create(['slug' => 'acme', 'name' => 'Acme Inc']);
        CurrentTenant::set($tenant);

        $site = Site::create([
            'slug' => 'hq',
            'name' => 'Acme HQ',
        ]);

        $this->assertSame($tenant->id, $site->tenant_id);
    }

    public function test_query_only_returns_models_for_current_tenant(): void
    {
        $tenantA = Tenant::create(['slug' => 'a', 'name' => 'Tenant A']);
        $tenantB = Tenant::create(['slug' => 'b', 'name' => 'Tenant B']);

        CurrentTenant::set($tenantA);
        Site::factory()->count(3)->create();

        CurrentTenant::set($tenantB);
        Site::factory()->count(2)->create();

        // In tenant A's context, only A's sites are visible.
        CurrentTenant::set($tenantA);
        $this->assertSame(3, Site::count());

        // In tenant B's context, only B's sites are visible.
        CurrentTenant::set($tenantB);
        $this->assertSame(2, Site::count());
    }

    public function test_query_with_no_tenant_in_scope_returns_empty(): void
    {
        $tenant = Tenant::create(['slug' => 'acme', 'name' => 'Acme Inc']);
        CurrentTenant::set($tenant);
        Site::factory()->count(3)->create();

        // Drop tenant context — queries must now return empty, never the
        // full unfiltered set.
        CurrentTenant::forget();

        $this->assertSame(0, Site::count());
    }

    public function test_without_global_scope_bypasses_tenant_filter(): void
    {
        $tenantA = Tenant::create(['slug' => 'a', 'name' => 'Tenant A']);
        $tenantB = Tenant::create(['slug' => 'b', 'name' => 'Tenant B']);

        CurrentTenant::set($tenantA);
        Site::factory()->count(3)->create();
        CurrentTenant::set($tenantB);
        Site::factory()->count(2)->create();

        // Super-admin path: explicit opt-out of the tenant scope returns all.
        CurrentTenant::forget();
        $all = Site::withoutGlobalScope(TenantScope::class)->get();

        $this->assertCount(5, $all);
    }

    public function test_explicit_tenant_id_on_creation_is_honored(): void
    {
        $tenantA = Tenant::create(['slug' => 'a', 'name' => 'Tenant A']);
        $tenantB = Tenant::create(['slug' => 'b', 'name' => 'Tenant B']);

        // Operating in A's context but explicitly assigning to B (super-admin path)
        CurrentTenant::set($tenantA);

        $site = new Site([
            'slug' => 'cross-tenant',
            'name' => 'Cross-tenant site',
        ]);
        $site->tenant_id = $tenantB->id;
        $site->save();

        $this->assertSame($tenantB->id, $site->fresh()->tenant_id);
    }
}
