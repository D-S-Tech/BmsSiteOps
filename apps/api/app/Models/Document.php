<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\DocumentStatus;
use App\Models\Concerns\BelongsToTenant;
use Database\Factories\DocumentFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * A knowledge-base document for RAG.
 *
 * The full text lives in `content`; the searchable units live in `chunks()`.
 * Lifecycle:
 *
 *   pending  -> (worker claims) embedding  -> ready | failed
 *
 * @property int $id
 * @property int $tenant_id
 * @property int|null $site_id
 * @property int|null $uploaded_by_user_id
 * @property string $title
 * @property string $source_type
 * @property string|null $source_ref
 * @property string $content
 * @property DocumentStatus $status
 * @property string|null $error
 * @property array<string, mixed>|null $metadata
 * @property Carbon|null $embedded_at
 * @property Carbon $created_at
 * @property Carbon $updated_at
 */
#[Fillable([
    'site_id',
    'uploaded_by_user_id',
    'title',
    'source_type',
    'source_ref',
    'content',
    'status',
    'error',
    'metadata',
    'embedded_at',
])]
class Document extends Model
{
    /** @use HasFactory<DocumentFactory> */
    use BelongsToTenant, HasFactory;

    protected function casts(): array
    {
        return [
            'status' => DocumentStatus::class,
            'metadata' => 'array',
            'embedded_at' => 'datetime',
        ];
    }

    public function site(): BelongsTo
    {
        return $this->belongsTo(Site::class);
    }

    public function uploadedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'uploaded_by_user_id');
    }

    public function chunks(): HasMany
    {
        return $this->hasMany(DocumentChunk::class)->orderBy('position');
    }
}
