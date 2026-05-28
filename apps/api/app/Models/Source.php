<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\SourceKind;
use App\Enums\SourceStatus;
use App\Models\Concerns\BelongsToTenant;
use Database\Factories\SourceFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * A configured external data source bound to a single site.
 *
 * A source is one instance of a monitoring system the platform polls — e.g.
 * "the Tactical RMM server for 4401 Northern Boulevard" or "the JACE at
 * 80 Pine Street". The Python worker loads active sources, runs the matching
 * collector for each, and pushes normalized devices and events back in.
 *
 * Credentials (API tokens, passwords) are stored encrypted at rest via the
 * `encrypted:array` cast. They are never returned by the public API — only
 * the worker reads them, over the internal HMAC-authenticated channel.
 *
 * @property int $id
 * @property int $tenant_id
 * @property int $site_id
 * @property SourceKind $kind
 * @property string $name
 * @property string|null $base_url
 * @property array $credentials encrypted at rest
 * @property int $poll_interval_seconds
 * @property bool $is_active
 * @property SourceStatus $last_status
 * @property Carbon|null $last_polled_at
 * @property string|null $last_error
 * @property array $metadata
 */
#[Fillable([
    'site_id',
    'kind',
    'name',
    'base_url',
    'credentials',
    'poll_interval_seconds',
    'is_active',
    'metadata',
])]
class Source extends Model
{
    /** @use HasFactory<SourceFactory> */
    use BelongsToTenant, HasFactory;

    /**
     * Model-level default so a fresh instance has last_status before insert.
     *
     * @var array<string, mixed>
     */
    protected $attributes = [
        'last_status' => 'never',
    ];

    protected function casts(): array
    {
        return [
            'kind' => SourceKind::class,
            'credentials' => 'encrypted:array',
            'poll_interval_seconds' => 'integer',
            'is_active' => 'boolean',
            'last_status' => SourceStatus::class,
            'last_polled_at' => 'datetime',
            'metadata' => 'array',
        ];
    }

    public function site(): BelongsTo
    {
        return $this->belongsTo(Site::class);
    }

    public function devices(): HasMany
    {
        return $this->hasMany(Device::class);
    }

    public function events(): HasMany
    {
        return $this->hasMany(Event::class);
    }
}
