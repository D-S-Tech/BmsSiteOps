<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Models\TriageRule;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin TriageRule
 */
class TriageRuleResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'description' => $this->description,
            'severity_match' => $this->severity_match,
            'kind_match' => $this->kind_match,
            'metric_pattern' => $this->metric_pattern,
            'value_contains' => $this->value_contains,
            'action' => $this->action->value,
            'action_label' => $this->action->getLabel(),
            'action_params' => $this->action_params,
            'priority' => $this->priority,
            'enabled' => $this->enabled,
            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }
}
