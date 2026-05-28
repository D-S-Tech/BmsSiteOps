<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Database\Factories\SiteBriefFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * An AI-generated natural-language brief for a site over an observation window.
 *
 * Briefs are produced by the worker (LLM via LiteLLM) from the site context
 * (device/source/event rollups + timeline) and pushed back over the internal
 * HMAC channel. They are append-only history: each generation is a new row, so
 * an operator can see how a site's narrative evolved day over day.
 *
 * @property int $id
 * @property int $tenant_id
 * @property int $site_id
 * @property string $summary the generated brief body
 * @property string $model which model produced it
 * @property Carbon $period_start window covered (start)
 * @property Carbon $period_end window covered (end)
 * @property array $metadata token counts + data snapshot
 * @property Carbon $generated_at when the brief was generated
 * @property Carbon $created_at when we persisted it
 */
#[Fillable([
    'site_id',
    'summary',
    'model',
    'period_start',
    'period_end',
    'metadata',
    'generated_at',
])]
class SiteBrief extends Model
{
    /** @use HasFactory<SiteBriefFactory> */
    use BelongsToTenant, HasFactory;

    /** Append-only: each generation is a new row; only created_at is meaningful. */
    public const UPDATED_AT = null;

    protected function casts(): array
    {
        return [
            'period_start' => 'datetime',
            'period_end' => 'datetime',
            'generated_at' => 'datetime',
            'metadata' => 'array',
        ];
    }

    public function site(): BelongsTo
    {
        return $this->belongsTo(Site::class);
    }
}
