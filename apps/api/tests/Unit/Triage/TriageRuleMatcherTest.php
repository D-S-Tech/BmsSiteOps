<?php

declare(strict_types=1);

namespace Tests\Unit\Triage;

use App\Enums\EventSeverity;
use App\Enums\SourceKind;
use App\Enums\TriageAction;
use App\Models\Event;
use App\Models\TriageRule;
use App\Services\Triage\TriageRuleMatcher;
use PHPUnit\Framework\TestCase;

/**
 * Exhaustive unit tests for TriageRuleMatcher.
 *
 * The matcher is pure (no DB, no Carbon::now, no auth), so we build Event +
 * TriageRule instances in memory with ->forceFill() — no factory, no
 * RefreshDatabase. Each test isolates one condition's behavior; the final
 * test composes multiple conditions.
 */
class TriageRuleMatcherTest extends TestCase
{
    private TriageRuleMatcher $matcher;

    protected function setUp(): void
    {
        parent::setUp();
        $this->matcher = new TriageRuleMatcher;
    }

    private function event(
        ?EventSeverity $severity = EventSeverity::Critical,
        SourceKind $kind = SourceKind::Trmm,
        string $metric = 'alert.disk_low',
        ?string $value = 'Disk space below 10%',
    ): Event {
        return (new Event)->forceFill([
            'severity' => $severity,
            'kind' => $kind,
            'metric' => $metric,
            'value' => $value,
        ]);
    }

    /**
     * @param  array<string, mixed>  $attrs
     */
    private function rule(array $attrs = []): TriageRule
    {
        return (new TriageRule)->forceFill(array_merge([
            'action' => TriageAction::MarkForReview,
            'severity_match' => null,
            'kind_match' => null,
            'metric_pattern' => null,
            'value_contains' => null,
        ], $attrs));
    }

    // --- catch-all -----------------------------------------------------------

    public function test_rule_with_no_conditions_matches_any_event(): void
    {
        $this->assertTrue($this->matcher->matches($this->event(), $this->rule()));
    }

    // --- severity_match ------------------------------------------------------

    public function test_severity_match_matches_when_equal(): void
    {
        $this->assertTrue($this->matcher->matches(
            $this->event(severity: EventSeverity::Critical),
            $this->rule(['severity_match' => 'critical'])
        ));
    }

    public function test_severity_match_fails_when_not_equal(): void
    {
        $this->assertFalse($this->matcher->matches(
            $this->event(severity: EventSeverity::Warning),
            $this->rule(['severity_match' => 'critical'])
        ));
    }

    public function test_severity_match_fails_when_event_severity_is_null(): void
    {
        $this->assertFalse($this->matcher->matches(
            $this->event(severity: null),
            $this->rule(['severity_match' => 'critical'])
        ));
    }

    // --- kind_match ----------------------------------------------------------

    public function test_kind_match_matches(): void
    {
        $this->assertTrue($this->matcher->matches(
            $this->event(kind: SourceKind::Trmm),
            $this->rule(['kind_match' => 'trmm'])
        ));
    }

    public function test_kind_match_fails(): void
    {
        $this->assertFalse($this->matcher->matches(
            $this->event(kind: SourceKind::Niagara),
            $this->rule(['kind_match' => 'trmm'])
        ));
    }

    // --- metric_pattern (fnmatch glob, case-insensitive) ---------------------

    public function test_metric_pattern_matches_exact(): void
    {
        $this->assertTrue($this->matcher->matches(
            $this->event(metric: 'cpu_high'),
            $this->rule(['metric_pattern' => 'cpu_high'])
        ));
    }

    public function test_metric_pattern_glob_prefix(): void
    {
        $this->assertTrue($this->matcher->matches(
            $this->event(metric: 'alert.disk_low'),
            $this->rule(['metric_pattern' => 'alert.*'])
        ));
    }

    public function test_metric_pattern_glob_anywhere(): void
    {
        $this->assertTrue($this->matcher->matches(
            $this->event(metric: 'discharge_temp'),
            $this->rule(['metric_pattern' => '*temp*'])
        ));
    }

    public function test_metric_pattern_is_case_insensitive(): void
    {
        $this->assertTrue($this->matcher->matches(
            $this->event(metric: 'Disk_Low'),
            $this->rule(['metric_pattern' => 'disk_*'])
        ));
    }

    public function test_metric_pattern_no_match(): void
    {
        $this->assertFalse($this->matcher->matches(
            $this->event(metric: 'cpu_high'),
            $this->rule(['metric_pattern' => 'disk_*'])
        ));
    }

    // --- value_contains (case-insensitive substring) -------------------------

    public function test_value_contains_matches_substring(): void
    {
        $this->assertTrue($this->matcher->matches(
            $this->event(value: 'Disk space below 10%'),
            $this->rule(['value_contains' => 'disk space'])
        ));
    }

    public function test_value_contains_is_case_insensitive(): void
    {
        $this->assertTrue($this->matcher->matches(
            $this->event(value: 'DISK SPACE LOW'),
            $this->rule(['value_contains' => 'disk space'])
        ));
    }

    public function test_value_contains_fails_when_value_is_null(): void
    {
        $this->assertFalse($this->matcher->matches(
            $this->event(value: null),
            $this->rule(['value_contains' => 'disk'])
        ));
    }

    public function test_value_contains_fails_when_substring_absent(): void
    {
        $this->assertFalse($this->matcher->matches(
            $this->event(value: 'all good'),
            $this->rule(['value_contains' => 'disk'])
        ));
    }

    // --- composite (multiple conditions all must match) ----------------------

    public function test_all_conditions_must_match(): void
    {
        $rule = $this->rule([
            'severity_match' => 'critical',
            'kind_match' => 'trmm',
            'metric_pattern' => 'alert.*',
            'value_contains' => 'disk',
        ]);

        // All match -> true
        $this->assertTrue($this->matcher->matches(
            $this->event(
                severity: EventSeverity::Critical,
                kind: SourceKind::Trmm,
                metric: 'alert.disk_low',
                value: 'Disk space critical',
            ),
            $rule
        ));

        // One fails (severity) -> false
        $this->assertFalse($this->matcher->matches(
            $this->event(
                severity: EventSeverity::Warning,
                kind: SourceKind::Trmm,
                metric: 'alert.disk_low',
                value: 'Disk space warn',
            ),
            $rule
        ));

        // One fails (metric) -> false
        $this->assertFalse($this->matcher->matches(
            $this->event(
                severity: EventSeverity::Critical,
                kind: SourceKind::Trmm,
                metric: 'cpu_high',
                value: 'Disk space critical',
            ),
            $rule
        ));
    }
}
