<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\TriageAction;
use App\Models\Concerns\BelongsToTenant;
use Database\Factories\TriageRuleFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * An operator-configured triage rule.
 *
 * Any non-null condition (severity_match, kind_match, metric_pattern,
 * value_contains) must match for the rule to fire. When multiple enabled rules
 * match a single event, the lowest `priority` value wins (ties broken by id).
 *
 * @property int $id
 * @property int $tenant_id
 * @property string $name
 * @property string|null $description
 * @property string|null $severity_match
 * @property string|null $kind_match
 * @property string|null $metric_pattern
 * @property string|null $value_contains
 * @property TriageAction $action
 * @property array|null $action_params
 * @property int $priority
 * @property bool $enabled
 * @property Carbon $created_at
 * @property Carbon $updated_at
 */
#[Fillable([
    'name',
    'description',
    'severity_match',
    'kind_match',
    'metric_pattern',
    'value_contains',
    'action',
    'action_params',
    'priority',
    'enabled',
])]
class TriageRule extends Model
{
    /** @use HasFactory<TriageRuleFactory> */
    use BelongsToTenant, HasFactory;

    protected function casts(): array
    {
        return [
            'action' => TriageAction::class,
            'action_params' => 'array',
            'enabled' => 'boolean',
            'priority' => 'integer',
        ];
    }

    public function decisions(): HasMany
    {
        return $this->hasMany(TriageDecision::class, 'rule_id');
    }

    /**
     * Enabled rules ordered by priority (ascending = highest priority first).
     *
     * @param  Builder<TriageRule>  $query
     */
    public function scopeActivePriority(Builder $query): void
    {
        $query->where('enabled', true)->orderBy('priority')->orderBy('id');
    }
}
