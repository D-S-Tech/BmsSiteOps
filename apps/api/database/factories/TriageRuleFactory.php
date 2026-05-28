<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Enums\TriageAction;
use App\Models\TriageRule;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<TriageRule>
 */
class TriageRuleFactory extends Factory
{
    protected $model = TriageRule::class;

    public function definition(): array
    {
        // tenant_id is set by the BelongsToTenant trait from CurrentTenant.
        return [
            'name' => fake()->unique()->words(3, true),
            'description' => null,
            'severity_match' => null,
            'kind_match' => null,
            'metric_pattern' => null,
            'value_contains' => null,
            'action' => TriageAction::MarkForReview,
            'action_params' => null,
            'priority' => 100,
            'enabled' => true,
        ];
    }

    public function action(TriageAction $action): self
    {
        return $this->state(['action' => $action]);
    }

    public function priority(int $priority): self
    {
        return $this->state(['priority' => $priority]);
    }

    public function disabled(): self
    {
        return $this->state(['enabled' => false]);
    }

    /**
     * @param  array<string, mixed>  $conditions
     */
    public function matching(array $conditions): self
    {
        return $this->state($conditions);
    }
}
