<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Database\Factories\DocumentChunkFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * One searchable chunk of a Document.
 *
 * The `embedding` column is stored as JSON-serialized floats (portable
 * between SQLite and PG); a future deployment-time optimization is to
 * migrate this column to pgvector's `vector(N)` type. The application
 * interface stays the same — see VectorStore in Sprint 7.2.
 *
 * @property int $id
 * @property int $tenant_id
 * @property int $document_id
 * @property int $position
 * @property string $content
 * @property int|null $token_count
 * @property array<int, float>|null $embedding
 * @property string|null $embedding_model
 * @property Carbon|null $embedded_at
 * @property Carbon $created_at
 * @property Carbon $updated_at
 */
#[Fillable([
    'document_id',
    'position',
    'content',
    'token_count',
    'embedding',
    'embedding_model',
    'embedded_at',
])]
class DocumentChunk extends Model
{
    /** @use HasFactory<DocumentChunkFactory> */
    use BelongsToTenant, HasFactory;

    protected function casts(): array
    {
        return [
            // JSON cast handles array<int,float> serialization transparently.
            'embedding' => 'array',
            'embedded_at' => 'datetime',
            'token_count' => 'integer',
            'position' => 'integer',
        ];
    }

    public function document(): BelongsTo
    {
        return $this->belongsTo(Document::class);
    }
}
