<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Enums\TriageAction;
use App\Enums\TriageStatus;
use App\Models\Event;
use App\Models\TriageDecision;
use App\Models\TriageRule;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<TriageDecision>
 */
class TriageDecisionFactory extends Factory
{
    protected $model = TriageDecision::class;

    public function definition(): array
    {
        // tenant_id is set by the BelongsToTenant trait from CurrentTenant.
        return [
            'event_id' => Event::factory(),
            'rule_id' => TriageRule::factory(),
            'site_id' => fn (array $attrs) => Event::withoutGlobalScopes()
                ->findOrFail($attrs['event_id'])->site_id,
            'action' => TriageAction::MarkForReview,
            'status' => TriageStatus::Pending,
            'notes' => null,
            'result' => null,
            'occurred_at' => now(),
        ];
    }

    public function forEvent(Event $event): self
    {
        return $this->state([
            'event_id' => $event->id,
            'site_id' => $event->site_id,
        ]);
    }

    public function forRule(TriageRule $rule): self
    {
        return $this->state([
            'rule_id' => $rule->id,
            'action' => $rule->action,
        ]);
    }

    public function status(TriageStatus $status): self
    {
        return $this->state(['status' => $status]);
    }
}
