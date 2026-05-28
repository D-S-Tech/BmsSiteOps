<?php

declare(strict_types=1);

namespace Tests\Feature\Api;

use App\Models\Site;
use App\Models\SiteBrief;
use App\Models\Tenant;
use App\Models\User;
use App\Support\CurrentTenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Tests for the public AI Site Brief read API (Sprint 4.1):
 *   GET /api/v1/sites/{site}/briefs
 *   GET /api/v1/sites/{site}/briefs/latest
 */
class SiteBriefTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    private Site $site;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();
        $this->tenant = Tenant::create(['slug' => 'acme', 'name' => 'Acme']);
        CurrentTenant::set($this->tenant);
        $this->site = Site::factory()->create();
        $this->user = User::factory()->inTenant($this->tenant)->create();
    }

    protected function tearDown(): void
    {
        CurrentTenant::forget();
        parent::tearDown();
    }

    public function test_index_requires_auth(): void
    {
        $this->getJson("/api/v1/sites/{$this->site->id}/briefs")->assertStatus(401);
    }

    public function test_index_lists_briefs_newest_first(): void
    {
        SiteBrief::factory()->forSite($this->site)->create([
            'generated_at' => now()->subDays(2),
            'summary' => 'Older brief',
        ]);
        SiteBrief::factory()->forSite($this->site)->create([
            'generated_at' => now(),
            'summary' => 'Newest brief',
        ]);

        Sanctum::actingAs($this->user);
        $response = $this->getJson("/api/v1/sites/{$this->site->id}/briefs")->assertOk();

        $data = $response->json('data');
        $this->assertCount(2, $data);
        $this->assertSame('Newest brief', $data[0]['summary']);
    }

    public function test_latest_returns_most_recent_brief(): void
    {
        SiteBrief::factory()->forSite($this->site)->create([
            'generated_at' => now()->subDay(),
            'summary' => 'Yesterday',
        ]);
        SiteBrief::factory()->forSite($this->site)->create([
            'generated_at' => now(),
            'summary' => 'Today',
            'model' => 'claude-sonnet-4-5',
        ]);

        Sanctum::actingAs($this->user);
        $this->getJson("/api/v1/sites/{$this->site->id}/briefs/latest")
            ->assertOk()
            ->assertJsonPath('data.summary', 'Today')
            ->assertJsonPath('data.model', 'claude-sonnet-4-5');
    }

    public function test_latest_returns_404_when_no_brief_exists(): void
    {
        Sanctum::actingAs($this->user);
        $this->getJson("/api/v1/sites/{$this->site->id}/briefs/latest")->assertStatus(404);
    }

    public function test_briefs_are_tenant_scoped(): void
    {
        // A brief on another tenant's site must not be reachable.
        $other = Tenant::create(['slug' => 'other', 'name' => 'Other']);
        CurrentTenant::set($other);
        $otherSite = Site::factory()->create();
        SiteBrief::factory()->forSite($otherSite)->create();
        CurrentTenant::set($this->tenant);

        Sanctum::actingAs($this->user);
        $this->getJson("/api/v1/sites/{$otherSite->id}/briefs")->assertStatus(404);
    }
}
