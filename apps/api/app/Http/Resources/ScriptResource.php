<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Models\Script;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin Script
 */
class ScriptResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'title' => $this->title,
            'prompt' => $this->prompt,
            'language' => $this->language->value,
            'language_label' => $this->language->getLabel(),
            'highlight_hint' => $this->language->highlightHint(),
            'status' => $this->status->value,
            'status_label' => $this->status->getLabel(),
            'is_pending' => $this->status->isPending(),
            'content' => $this->content,
            'model' => $this->model,
            'error' => $this->error,
            'metadata' => $this->metadata,
            'requested_at' => $this->requested_at?->toIso8601String(),
            'claimed_at' => $this->claimed_at?->toIso8601String(),
            'generated_at' => $this->generated_at?->toIso8601String(),
            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }
}
