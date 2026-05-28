<?php

declare(strict_types=1);

namespace Tests\Feature\Triage;

use App\Enums\EventSeverity;
use App\Enums\SourceKind;
use App\Enums\TriageAction;
use App\Enums\TriageStatus;
use App\Models\Device;
use App\Models\Event;
use App\Models\Site;
use App\Models\Source;
use App\Models\Tenant;
use App\Models\TriageDecision;
use App\Models\TriageRule;
use App\Services\Triage\TriageService;
use App\Support\CurrentTenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Integration tests for TriageService.evaluate — finds the highest-priority
 * matching enabled rule and persists a pending decision.
 */
class TriageServiceTest extends TestCase
{
    use RefreshDatabase;

    private TriageService $service;

    private Tenant $tenant;

    private Source $source;

    private Device $device;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = app(TriageService::class);

        $this->tenant = Tenant::create(['slug' => 'acme', 'name' => 'Acme']);
        CurrentTenant::set($this->tenant);

        $site = Site::factory()->create();
        $this->source = Source::factory()->forSite($site)->kind(SourceKind::Trmm)->create();
        $this->device = Device::factory()->forSource($this->source)->create();
    }

    protected function tearDown(): void
    {
        CurrentTenant::forget();
        parent::tearDown();
    }

    public function test_returns_null_when_no_rule_matches(): void
    {
        TriageRule::factory()->matching(['severity_match' => 'critical'])->create();

        $event = Event::factory()->forDevice($this->device)
            ->severity(EventSeverity::Info)->create();

        $this->assertNull($this->service->evaluate($event));
        $this->assertSame(0, TriageDecision::count());
    }

    public function test_persists_a_pending_decision_when_a_rule_matches(): void
    {
        $rule = TriageRule::factory()
            ->matching(['severity_match' => 'critical'])
            ->action(TriageAction::MarkForReview)
            ->create();

        $event = Event::factory()->forDevice($this->device)
            ->severity(EventSeverity::Critical)->create();

        $decision = $this->service->evaluate($event);

        $this->assertNotNull($decision);
        $this->assertSame($rule->id, $decision->rule_id);
        $this->assertSame($event->id, $decision->event_id);
        $this->assertSame($event->site_id, $decision->site_id);
        $this->assertSame(TriageAction::MarkForReview, $decision->action);
        $this->assertSame(TriageStatus::Pending, $decision->status);
        $this->assertSame(1, TriageDecision::count());
    }

    public function test_picks_highest_priority_rule_when_multiple_match(): void
    {
        TriageRule::factory()
            ->matching(['severity_match' => 'critical'])
            ->action(TriageAction::MarkForReview)
            ->priority(500)
            ->create();
        $winner = TriageRule::factory()
            ->matching(['severity_match' => 'critical', 'metric_pattern' => 'disk*'])
            ->action(TriageAction::MuteDevice)
            ->priority(50)
            ->create();
        TriageRule::factory()
            ->matching(['severity_match' => 'critical'])
            ->action(TriageAction::Ignore)
            ->priority(200)
            ->create();

        $event = Event::factory()->forDevice($this->device)
            ->severity(EventSeverity::Critical)
            ->metric('disk_low', '95')
            ->create();

        $decision = $this->service->evaluate($event);
        $this->assertNotNull($decision);
        $this->assertSame($winner->id, $decision->rule_id);
        $this->assertSame(TriageAction::MuteDevice, $decision->action);
    }

    public function test_disabled_rules_are_skipped(): void
    {
        // The only matching rule is disabled — no decision.
        TriageRule::factory()
            ->matching(['severity_match' => 'critical'])
            ->disabled()
            ->create();

        $event = Event::factory()->forDevice($this->device)
            ->severity(EventSeverity::Critical)->create();

        $this->assertNull($this->service->evaluate($event));
    }

    public function test_rules_are_tenant_scoped(): void
    {
        // Other tenant has a critical-severity rule, but it must not apply.
        $other = Tenant::create(['slug' => 'other', 'name' => 'Other']);
        CurrentTenant::set($other);
        TriageRule::factory()->matching(['severity_match' => 'critical'])->create();
        CurrentTenant::set($this->tenant);

        $event = Event::factory()->forDevice($this->device)
            ->severity(EventSeverity::Critical)->create();

        $this->assertNull($this->service->evaluate($event));
    }

    public function test_evaluate_many_returns_one_decision_per_matched_event(): void
    {
        TriageRule::factory()->matching(['severity_match' => 'critical'])->create();

        $a = Event::factory()->forDevice($this->device)->severity(EventSeverity::Critical)->create();
        $b = Event::factory()->forDevice($this->device)->severity(EventSeverity::Info)->create();
        $c = Event::factory()->forDevice($this->device)->severity(EventSeverity::Critical)->create();

        $decisions = $this->service->evaluateMany([$a, $b, $c]);

        $this->assertCount(2, $decisions);
        $this->assertSame([$a->id, $c->id], array_map(fn ($d) => $d->event_id, $decisions));
    }
}
