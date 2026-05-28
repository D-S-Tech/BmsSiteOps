<?php

declare(strict_types=1);

namespace Tests\Feature\Api;

use App\Enums\TriageAction;
use App\Enums\TriageStatus;
use App\Models\Device;
use App\Models\Event;
use App\Models\Site;
use App\Models\Source;
use App\Models\Tenant;
use App\Models\TriageDecision;
use App\Models\TriageRule;
use App\Models\User;
use App\Support\CurrentTenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Public read API for triage rules and the per-site decision audit log.
 */
class TriageApiTest extends TestCase
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
        $this->site = Site::factory()->create();
        $this->user = User::factory()->inTenant($this->tenant)->create();
    }

    protected function tearDown(): void
    {
        CurrentTenant::forget();
        parent::tearDown();
    }

    // --- triage-rules --------------------------------------------------------

    public function test_rules_index_requires_auth(): void
    {
        $this->getJson('/api/v1/triage-rules')->assertStatus(401);
    }

    public function test_rules_index_lists_enabled_first_then_by_priority(): void
    {
        $disabled = TriageRule::factory()->disabled()->priority(10)->create(['name' => 'Disabled high-pri']);
        $highPri = TriageRule::factory()->priority(10)->create(['name' => 'High priority']);
        $lowPri = TriageRule::factory()->priority(500)->create(['name' => 'Low priority']);

        Sanctum::actingAs($this->user);
        $response = $this->getJson('/api/v1/triage-rules')->assertOk();

        $names = array_column($response->json('data'), 'name');
        $this->assertSame([$highPri->name, $lowPri->name, $disabled->name], $names);
    }

    public function test_rules_show(): void
    {
        $rule = TriageRule::factory()->create([
            'name' => 'Mute disk warnings',
            'severity_match' => 'warning',
            'metric_pattern' => 'disk*',
            'action' => TriageAction::Ignore,
        ]);

        Sanctum::actingAs($this->user);
        $this->getJson("/api/v1/triage-rules/{$rule->id}")
            ->assertOk()
            ->assertJsonPath('data.name', 'Mute disk warnings')
            ->assertJsonPath('data.severity_match', 'warning')
            ->assertJsonPath('data.metric_pattern', 'disk*')
            ->assertJsonPath('data.action', 'ignore')
            ->assertJsonPath('data.action_label', 'Ignore');
    }

    public function test_rules_are_tenant_scoped(): void
    {
        $other = Tenant::create(['slug' => 'other', 'name' => 'Other']);
        CurrentTenant::set($other);
        $otherRule = TriageRule::factory()->create();
        CurrentTenant::set($this->tenant);

        Sanctum::actingAs($this->user);
        $this->getJson("/api/v1/triage-rules/{$otherRule->id}")->assertStatus(404);
    }

    // --- triage-decisions ----------------------------------------------------

    public function test_decisions_index_requires_auth(): void
    {
        $this->getJson("/api/v1/sites/{$this->site->id}/triage-decisions")->assertStatus(401);
    }

    public function test_decisions_index_returns_audit_log_newest_first(): void
    {
        $source = Source::factory()->forSite($this->site)->create();
        $device = Device::factory()->forSource($source)->create();
        $rule = TriageRule::factory()->create();

        $oldEvent = Event::factory()->forDevice($device)->create(['occurred_at' => now()->subHour()]);
        $newEvent = Event::factory()->forDevice($device)->create(['occurred_at' => now()]);

        TriageDecision::factory()->forEvent($oldEvent)->forRule($rule)
            ->status(TriageStatus::Executed)
            ->create(['occurred_at' => now()->subHour()]);
        TriageDecision::factory()->forEvent($newEvent)->forRule($rule)
            ->status(TriageStatus::Pending)
            ->create(['occurred_at' => now()]);

        Sanctum::actingAs($this->user);
        $response = $this->getJson("/api/v1/sites/{$this->site->id}/triage-decisions")->assertOk();

        $rows = $response->json('data');
        $this->assertCount(2, $rows);
        $this->assertSame('pending', $rows[0]['status']);
        $this->assertSame('executed', $rows[1]['status']);
        // The embedded rule is included via with('rule').
        $this->assertSame($rule->id, $rows[0]['rule']['id']);
    }

    public function test_decisions_are_tenant_scoped(): void
    {
        $other = Tenant::create(['slug' => 'other', 'name' => 'Other']);
        CurrentTenant::set($other);
        $otherSite = Site::factory()->create();
        CurrentTenant::set($this->tenant);

        Sanctum::actingAs($this->user);
        $this->getJson("/api/v1/sites/{$otherSite->id}/triage-decisions")->assertStatus(404);
    }
}
