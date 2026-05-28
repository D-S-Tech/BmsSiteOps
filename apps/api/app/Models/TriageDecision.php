<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\TriageAction;
use App\Enums\TriageStatus;
use App\Models\Concerns\BelongsToTenant;
use Database\Factories\TriageDecisionFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * Append-only audit log of a triage rule firing on an event.
 *
 * event_id is intentionally NOT a foreign key — `events` is a TimescaleDB
 * hypertable on production and TimescaleDB does not support FK references TO
 * hypertables (see ADR 0008). Integrity is maintained at the application
 * layer: decisions are only created from inside the ingestion path where the
 * event was just inserted.
 *
 * @property int $id
 * @property int $tenant_id
 * @property int $event_id non-FK reference to events
 * @property int $rule_id
 * @property int $site_id
 * @property TriageAction $action
 * @property TriageStatus $status
 * @property string|null $notes
 * @property array|null $result
 * @property Carbon $occurred_at
 * @property Carbon $created_at
 */
#[Fillable([
    'event_id',
    'rule_id',
    'site_id',
    'action',
    'status',
    'notes',
    'result',
    'occurred_at',
])]
class TriageDecision extends Model
{
    /** @use HasFactory<TriageDecisionFactory> */
    use BelongsToTenant, HasFactory;

    /** Append-only: only created_at is meaningful. */
    public const UPDATED_AT = null;

    protected function casts(): array
    {
        return [
            'action' => TriageAction::class,
            'status' => TriageStatus::class,
            'result' => 'array',
            'occurred_at' => 'datetime',
        ];
    }

    public function rule(): BelongsTo
    {
        return $this->belongsTo(TriageRule::class, 'rule_id');
    }

    public function site(): BelongsTo
    {
        return $this->belongsTo(Site::class);
    }
}
