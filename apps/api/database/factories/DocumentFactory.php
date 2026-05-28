<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Enums\DocumentStatus;
use App\Models\Document;
use App\Models\Site;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Document>
 */
class DocumentFactory extends Factory
{
    protected $model = Document::class;

    public function definition(): array
    {
        // tenant_id is set by the BelongsToTenant trait from CurrentTenant.
        return [
            'site_id' => null,
            'uploaded_by_user_id' => null,
            'title' => fake()->sentence(4),
            'source_type' => 'manual',
            'source_ref' => null,
            'content' => fake()->paragraphs(3, true),
            'status' => DocumentStatus::Pending,
            'error' => null,
            'metadata' => null,
            'embedded_at' => null,
        ];
    }

    public function status(DocumentStatus $status): self
    {
        return $this->state(['status' => $status]);
    }

    public function ready(): self
    {
        return $this->state([
            'status' => DocumentStatus::Ready,
            'embedded_at' => now(),
        ]);
    }

    public function failed(string $error = 'embedding model unreachable'): self
    {
        return $this->state([
            'status' => DocumentStatus::Failed,
            'error' => $error,
        ]);
    }

    public function forSite(Site $site): self
    {
        return $this->state(['site_id' => $site->id]);
    }
}
