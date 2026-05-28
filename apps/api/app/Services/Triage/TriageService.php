<?php

declare(strict_types=1);

namespace App\Services\Triage;

use App\Models\Event;
use App\Models\TriageDecision;
use App\Models\TriageRule;

/**
 * Evaluates triage rules against events and records decisions.
 *
 * Sprint 5.2: when a rule matches, the prescribed action is executed
 * immediately by TriageActionExecutor and the decision is persisted with the
 * resulting TriageStatus (executed | skipped | failed). No 'pending' decisions
 * are created — every match is fully resolved in-line.
 *
 * Tenant resolution: TriageRule and TriageDecision are tenant-scoped through
 * their BelongsToTenant trait, so callers must ensure CurrentTenant is set
 * before calling. From inside ingestion (SourceSyncService) the tenant is
 * already established from the source.
 */
class TriageService
{
    public function __construct(
        private readonly TriageRuleMatcher $matcher,
        private readonly TriageActionExecutor $executor,
    ) {}

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

        [$status, $result] = $this->executor->execute($rule, $event);

        return TriageDecision::create([
            'event_id' => $event->id,
            'rule_id' => $rule->id,
            'site_id' => $event->site_id,
            'action' => $rule->action,
            'status' => $status,
            'result' => $result,
            'occurred_at' => $event->occurred_at,
        ]);
    }

    /**
     * Evaluate every event in turn. Returns the decisions created (one per
     * matched event; events without a matching rule are skipped).
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
        $rules = TriageRule::query()->activePriority()->get();

        foreach ($rules as $rule) {
            if ($this->matcher->matches($event, $rule)) {
                return $rule;
            }
        }

        return null;
    }
}
