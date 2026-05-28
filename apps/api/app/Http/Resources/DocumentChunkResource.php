<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Models\DocumentChunk;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin DocumentChunk
 */
class DocumentChunkResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'document_id' => $this->document_id,
            'position' => $this->position,
            'content' => $this->content,
            'token_count' => $this->token_count,
            'embedded' => $this->embedding !== null,
            'embedding_model' => $this->embedding_model,
            'embedded_at' => $this->embedded_at?->toIso8601String(),
        ];
    }
}
