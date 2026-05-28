<?php

declare(strict_types=1);

namespace App\Services\Triage;

use App\Enums\TriageStatus;
use App\Models\Event;
use App\Models\TriageDecision;
use App\Models\TriageRule;

/**
 * Evaluates triage rules against events and records decisions.
 *
 * Sprint 5.1 scope: discovers the highest-priority matching enabled rule and
 * persists a TriageDecision with status='pending'. Action *execution* (muting
 * a device, marking for review, etc.) is wired in Sprint 5.2 — keeping the
 * evaluator pure and easy to reason about.
 *
 * Tenant resolution: TriageRule and TriageDecision are tenant-scoped through
 * their BelongsToTenant trait, so callers must ensure CurrentTenant is set
 * before calling. From inside ingestion (the planned 5.2 wiring) the tenant
 * is already set by SourceSyncService.
 */
class TriageService
{
    public function __construct(private readonly TriageRuleMatcher $matcher) {}

    /**
     * Evaluate a single event. Returns the persisted decision if any rule
     * matched, or null when no rule applies.
     */
    public function evaluate(Event $event): ?TriageDecision
    {
        $rule = $this->firstMatchingRule($event);

        if ($rule === null) {
            return null;
        }

        return TriageDecision::create([
            'event_id' => $event->id,
            'rule_id' => $rule->id,
            'site_id' => $event->site_id,
            'action' => $rule->action,
            'status' => TriageStatus::Pending,
            'occurred_at' => $event->occurred_at,
        ]);
    }

    /**
     * Evaluate every event in turn. Returns the list of decisions created
     * (one per matching event; events without a matching rule are skipped).
     *
     * @param  iterable<Event>  $events
     * @return list<TriageDecision>
     */
    public function evaluateMany(iterable $events): array
    {
        $decisions = [];

        foreach ($events as $event) {
            $decision = $this->evaluate($event);
            if ($decision !== null) {
                $decisions[] = $decision;
            }
        }

        return $decisions;
    }

    private function firstMatchingRule(Event $event): ?TriageRule
    {
        // Tenant scope is already on the model via BelongsToTenant — this
        // only sees the current tenant's rules.
        $rules = TriageRule::query()->activePriority()->get();

        foreach ($rules as $rule) {
            if ($this->matcher->matches($event, $rule)) {
                return $rule;
            }
        }

        return null;
    }
}
