<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Models\Document;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin Document
 */
class DocumentResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'site_id' => $this->site_id,
            'title' => $this->title,
            'source_type' => $this->source_type,
            'source_ref' => $this->source_ref,
            'status' => $this->status->value,
            'status_label' => $this->status->getLabel(),
            'is_pending' => $this->status->isPending(),
            'content' => $this->when($request->boolean('include_content'), $this->content),
            'error' => $this->error,
            'metadata' => $this->metadata,
            'chunks_count' => $this->whenCounted('chunks'),
            'chunks' => DocumentChunkResource::collection($this->whenLoaded('chunks')),
            'embedded_at' => $this->embedded_at?->toIso8601String(),
            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }
}
