<?php

declare(strict_types=1);

namespace App\Services\Triage;

use App\Models\Event;
use App\Models\TriageRule;

/**
 * Pure-function predicate: does this event match this rule?
 *
 * A rule matches an event iff every NON-NULL condition matches. A null
 * condition is a wildcard. Conditions:
 *
 *  - severity_match  exact match against the event's severity (case-sensitive,
 *                    enum value: "critical" | "warning" | "info"). An event
 *                    with a null severity does not match a non-null condition.
 *  - kind_match      exact match against the event's source kind ("trmm" |
 *                    "niagara" | "bacnet").
 *  - metric_pattern  fnmatch glob against event.metric, case-insensitive
 *                    (e.g. "disk*", "*temp*").
 *  - value_contains  case-insensitive substring on event.value. An event with
 *                    a null value does not match a non-null condition.
 *
 * This class is deliberately stateless and has no database, time, or auth
 * dependencies, so it is exhaustively unit-tested with no fixtures.
 */
class TriageRuleMatcher
{
    public function matches(Event $event, TriageRule $rule): bool
    {
        if ($rule->severity_match !== null) {
            if ($event->severity === null
                || $event->severity->value !== $rule->severity_match
            ) {
                return false;
            }
        }

        if ($rule->kind_match !== null
            && $event->kind->value !== $rule->kind_match
        ) {
            return false;
        }

        if ($rule->metric_pattern !== null
            && ! fnmatch($rule->metric_pattern, $event->metric, FNM_CASEFOLD)
        ) {
            return false;
        }

        if ($rule->value_contains !== null) {
            if ($event->value === null) {
                return false;
            }
            if (stripos($event->value, $rule->value_contains) === false) {
                return false;
            }
        }

        return true;
    }
}
