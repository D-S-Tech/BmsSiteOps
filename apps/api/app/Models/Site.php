<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Database\Factories\SiteFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * A site is a single commercial building or campus that BmsSiteOps monitors.
 *
 * Each site belongs to exactly one tenant. The site is the unit of operation
 * that data sources (TRMM, Niagara, BACnet) and the AI layer both organize
 * around — "what's going on at 4401 Northern Boulevard" is a per-site question.
 *
 * @property int $id
 * @property int $tenant_id
 * @property string $slug URL-safe identifier, unique within tenant
 * @property string $name
 * @property string|null $address
 * @property string|null $timezone Olson zone identifier (e.g., "America/New_York")
 * @property array $metadata freeform site metadata as JSON
 */
#[Fillable(['slug', 'name', 'address', 'timezone', 'metadata'])]
class Site extends Model
{
    /** @use HasFactory<SiteFactory> */
    use BelongsToTenant, HasFactory;

    protected function casts(): array
    {
        return [
            'metadata' => 'array',
        ];
    }
}
