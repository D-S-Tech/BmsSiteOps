<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\ScriptLanguage;
use App\Enums\ScriptStatus;
use App\Models\Concerns\BelongsToTenant;
use Database\Factories\ScriptFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * An operator-requested AI script generation.
 *
 * Lifecycle: Requested -> (claimed by worker) Generating -> Ready | Failed.
 * Only Ready scripts have `content`; only Failed scripts have `error`.
 *
 * @property int $id
 * @property int $tenant_id
 * @property int|null $requested_by_user_id
 * @property string $title
 * @property string $prompt
 * @property ScriptLanguage $language
 * @property ScriptStatus $status
 * @property string|null $content
 * @property string|null $model
 * @property string|null $error
 * @property array<string, mixed>|null $metadata
 * @property Carbon $requested_at
 * @property Carbon|null $claimed_at
 * @property Carbon|null $generated_at
 * @property Carbon $created_at
 * @property Carbon $updated_at
 */
#[Fillable([
    'requested_by_user_id',
    'title',
    'prompt',
    'language',
    'status',
    'content',
    'model',
    'error',
    'metadata',
    'requested_at',
    'claimed_at',
    'generated_at',
])]
class Script extends Model
{
    /** @use HasFactory<ScriptFactory> */
    use BelongsToTenant, HasFactory;

    protected function casts(): array
    {
        return [
            'language' => ScriptLanguage::class,
            'status' => ScriptStatus::class,
            'metadata' => 'array',
            'requested_at' => 'datetime',
            'claimed_at' => 'datetime',
            'generated_at' => 'datetime',
        ];
    }

    public function requestedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'requested_by_user_id');
    }
}
