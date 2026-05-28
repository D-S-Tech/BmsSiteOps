<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\QuestionStatus;
use App\Models\Concerns\BelongsToTenant;
use Database\Factories\QuestionFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * A Site Q&A question + its answer.
 *
 * @property int $id
 * @property int $tenant_id
 * @property int|null $site_id
 * @property int|null $asked_by_user_id
 * @property string $question
 * @property string|null $answer
 * @property QuestionStatus $status
 * @property string|null $error
 * @property string|null $model
 * @property array<string, mixed>|null $metadata
 * @property Carbon $asked_at
 * @property Carbon|null $answered_at
 * @property Carbon $created_at
 * @property Carbon $updated_at
 */
#[Fillable([
    'site_id',
    'asked_by_user_id',
    'question',
    'answer',
    'status',
    'error',
    'model',
    'metadata',
    'asked_at',
    'answered_at',
])]
class Question extends Model
{
    /** @use HasFactory<QuestionFactory> */
    use BelongsToTenant, HasFactory;

    protected function casts(): array
    {
        return [
            'status' => QuestionStatus::class,
            'metadata' => 'array',
            'asked_at' => 'datetime',
            'answered_at' => 'datetime',
        ];
    }

    public function site(): BelongsTo
    {
        return $this->belongsTo(Site::class);
    }

    public function askedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'asked_by_user_id');
    }
}
