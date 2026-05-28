<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Models\Question;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin Question
 */
class QuestionResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'site_id' => $this->site_id,
            'question' => $this->question,
            'answer' => $this->answer,
            'status' => $this->status->value,
            'status_label' => $this->status->getLabel(),
            'error' => $this->error,
            'model' => $this->model,
            // Surface citations at the top level for an easy frontend; full
            // metadata blob is also exposed for power users / debugging.
            'citations' => $this->metadata['citations'] ?? [],
            'metadata' => $this->metadata,
            'asked_at' => $this->asked_at?->toIso8601String(),
            'answered_at' => $this->answered_at?->toIso8601String(),
            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }
}
