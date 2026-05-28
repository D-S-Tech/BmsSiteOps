<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Document;
use App\Models\DocumentChunk;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<DocumentChunk>
 */
class DocumentChunkFactory extends Factory
{
    protected $model = DocumentChunk::class;

    public function definition(): array
    {
        // tenant_id is set by the BelongsToTenant trait from CurrentTenant.
        return [
            'document_id' => Document::factory(),
            'position' => 0,
            'content' => fake()->paragraph(),
            'token_count' => null,
            'embedding' => null,
            'embedding_model' => null,
            'embedded_at' => null,
        ];
    }

    public function forDocument(Document $document): self
    {
        return $this->state(['document_id' => $document->id]);
    }

    public function position(int $position): self
    {
        return $this->state(['position' => $position]);
    }

    /**
     * @param  array<int, float>|null  $vector
     */
    public function embedded(?array $vector = null, string $model = 'ollama/nomic-embed-text'): self
    {
        return $this->state([
            'embedding' => $vector ?? [0.1, 0.2, 0.3],
            'embedding_model' => $model,
            'embedded_at' => now(),
        ]);
    }
}
