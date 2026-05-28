<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Models\TriageDecision;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin TriageDecision
 */
class TriageDecisionResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'event_id' => $this->event_id,
            'rule_id' => $this->rule_id,
            'site_id' => $this->site_id,
            'action' => $this->action->value,
            'action_label' => $this->action->getLabel(),
            'status' => $this->status->value,
            'status_label' => $this->status->getLabel(),
            'notes' => $this->notes,
            'result' => $this->result,
            'occurred_at' => $this->occurred_at?->toIso8601String(),
            'created_at' => $this->created_at?->toIso8601String(),
            'rule' => TriageRuleResource::make($this->whenLoaded('rule')),
        ];
    }
}
