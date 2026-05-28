<?php

declare(strict_types=1);

namespace Tests\Feature\Triage;

use App\Enums\EventSeverity;
use App\Enums\TriageAction;
use App\Enums\TriageStatus;
use App\Models\Device;
use App\Models\Event;
use App\Models\Site;
use App\Models\Source;
use App\Models\Tenant;
use App\Models\TriageRule;
use App\Services\Triage\TriageActionExecutor;
use App\Support\CurrentTenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Unit tests for TriageActionExecutor.
 *
 * The executor is small but it mutates the database (muting a device), so it
 * uses RefreshDatabase rather than the pure-matcher approach. Each action's
 * behavior is covered: mute_device with and without a duration, mute_device
 * on a missing device, mark_for_review, and ignore.
 */
class TriageActionExecutorTest extends TestCase
{
    use RefreshDatabase;

    private TriageActionExecutor $executor;

    private Device $device;

    protected function setUp(): void
    {
        parent::setUp();
        $this->executor = new TriageActionExecutor;

        $tenant = Tenant::create(['slug' => 'acme', 'name' => 'Acme']);
        CurrentTenant::set($tenant);
        $site = Site::factory()->create();
        $source = Source::factory()->forSite($site)->create();
        $this->device = Device::factory()->forSource($source)->create();
    }

    protected function tearDown(): void
    {
        CurrentTenant::forget();
        parent::tearDown();
    }

    public function test_mute_device_mutes_indefinitely_with_no_params(): void
    {
        $rule = TriageRule::factory()->action(TriageAction::MuteDevice)->create();
        $event = Event::factory()->forDevice($this->device)->create();

        [$status, $result] = $this->executor->execute($rule, $event);

        $this->assertSame(TriageStatus::Executed, $status);
        $this->assertSame($this->device->id, $result['muted_device_id']);
        $this->assertNull($result['muted_until']);
        $this->assertTrue($this->device->fresh()->is_muted);
    }

    public function test_mute_device_with_duration_minutes_sets_window(): void
    {
        $rule = TriageRule::factory()
            ->action(TriageAction::MuteDevice)
            ->create(['action_params' => ['duration_minutes' => 30]]);
        $event = Event::factory()->forDevice($this->device)->create();

        [$status, $result] = $this->executor->execute($rule, $event);

        $this->assertSame(TriageStatus::Executed, $status);
        $this->assertNotNull($result['muted_until']);
        $this->assertTrue($this->device->fresh()->muted_until->isFuture());
    }

    public function test_mute_device_returns_failed_when_device_missing(): void
    {
        $rule = TriageRule::factory()->action(TriageAction::MuteDevice)->create();
        // Build an Event referencing a non-existent device id (no DB save).
        $event = (new Event)->forceFill([
            'device_id' => 999999,
            'site_id' => $this->device->site_id,
            'severity' => EventSeverity::Critical,
        ]);

        [$status, $result] = $this->executor->execute($rule, $event);

        $this->assertSame(TriageStatus::Failed, $status);
        $this->assertSame('device not found', $result['error']);
    }

    public function test_mark_for_review_returns_executed_with_no_result(): void
    {
        $rule = TriageRule::factory()->action(TriageAction::MarkForReview)->create();
        $event = Event::factory()->forDevice($this->device)->create();

        [$status, $result] = $this->executor->execute($rule, $event);

        $this->assertSame(TriageStatus::Executed, $status);
        $this->assertNull($result);
        // Device is untouched.
        $this->assertFalse($this->device->fresh()->is_muted);
    }

    public function test_ignore_returns_skipped(): void
    {
        $rule = TriageRule::factory()->action(TriageAction::Ignore)->create();
        $event = Event::factory()->forDevice($this->device)->create();

        [$status, $result] = $this->executor->execute($rule, $event);

        $this->assertSame(TriageStatus::Skipped, $status);
        $this->assertNull($result);
    }
}
