<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\DeviceStatus;
use App\Models\Concerns\BelongsToTenant;
use Database\Factories\DeviceFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * A monitored device in the unified registry.
 *
 * Devices are normalized across all source kinds: a TRMM agent, a Niagara
 * station point, and a BACnet object all become Device rows. The `external_id`
 * is the device's identifier in its originating source; the pair
 * (source_id, external_id) is unique.
 *
 * site_id is denormalized from the parent source so that site-level dashboards
 * can query devices without joining through sources.
 *
 * @property int $id
 * @property int $tenant_id
 * @property int $source_id
 * @property int $site_id
 * @property string $external_id identifier in the source system
 * @property string $name
 * @property string|null $type e.g. server, workstation, controller, sensor
 * @property DeviceStatus $status
 * @property Carbon|null $last_seen_at
 * @property array $metadata
 */
#[Fillable([
    'source_id',
    'site_id',
    'external_id',
    'name',
    'type',
    'status',
    'last_seen_at',
    'metadata',
])]
class Device extends Model
{
    /** @use HasFactory<DeviceFactory> */
    use BelongsToTenant, HasFactory;

    protected function casts(): array
    {
        return [
            'status' => DeviceStatus::class,
            'last_seen_at' => 'datetime',
            'metadata' => 'array',
        ];
    }

    public function source(): BelongsTo
    {
        return $this->belongsTo(Source::class);
    }

    public function site(): BelongsTo
    {
        return $this->belongsTo(Site::class);
    }

    public function events(): HasMany
    {
        return $this->hasMany(Event::class);
    }
}
