<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\EventSeverity;
use App\Enums\SourceKind;
use App\Models\Concerns\BelongsToTenant;
use Database\Factories\EventFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * A normalized event emitted by a collector and ingested via the worker.
 *
 * Events are the atomic unit of "something happened" — an alert firing, a
 * metric reading, a state change. They carry the originating device, the
 * metric name, a value (stored as a string and interpreted per metric), and
 * an optional severity for triage.
 *
 * source_id and site_id are denormalized from the device so that triage and
 * site-brief queries can filter by site/time without extra joins. The shape
 * mirrors `CollectorEvent` in the Python worker.
 *
 * @property int $id
 * @property int $tenant_id
 * @property int $device_id
 * @property int $source_id
 * @property int $site_id
 * @property SourceKind $kind
 * @property string $metric
 * @property string|null $value
 * @property EventSeverity|null $severity
 * @property Carbon $occurred_at when it happened in the source
 * @property array $metadata
 * @property Carbon $created_at when we ingested it
 */
#[Fillable([
    'device_id',
    'source_id',
    'site_id',
    'kind',
    'metric',
    'value',
    'severity',
    'occurred_at',
    'metadata',
])]
class Event extends Model
{
    /** @use HasFactory<EventFactory> */
    use BelongsToTenant, HasFactory;

    /**
     * Events are immutable append-only records — only created_at is meaningful.
     * occurred_at carries the source-side timestamp.
     */
    public const UPDATED_AT = null;

    protected function casts(): array
    {
        return [
            'kind' => SourceKind::class,
            'severity' => EventSeverity::class,
            'occurred_at' => 'datetime',
            'metadata' => 'array',
        ];
    }

    public function device(): BelongsTo
    {
        return $this->belongsTo(Device::class);
    }

    public function source(): BelongsTo
    {
        return $this->belongsTo(Source::class);
    }

    public function site(): BelongsTo
    {
        return $this->belongsTo(Site::class);
    }
}
