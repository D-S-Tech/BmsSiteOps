<?php

declare(strict_types=1);

namespace App\Models;

use Database\Factories\TenantFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A tenant is a top-level isolated workspace.
 *
 * The first tenant is BMCE itself. Each commercial customer (post-launch)
 * becomes a separate tenant. All tenant-scoped models carry a `tenant_id`
 * referencing this table, with a global scope (see App\Models\Scopes\TenantScope)
 * filtering queries to the currently active tenant.
 *
 * See: docs/adr/0002-multi-tenancy-row-level.md
 *
 * @property int $id
 * @property string $slug URL-safe identifier, unique
 * @property string $name display name
 * @property bool $is_active soft-disable without deleting
 */
#[Fillable(['slug', 'name', 'is_active'])]
class Tenant extends Model
{
    /** @use HasFactory<TenantFactory> */
    use HasFactory;

    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
        ];
    }

    /**
     * The users who have access to this tenant. Pivot includes the role.
     */
    public function users(): BelongsToMany
    {
        return $this->belongsToMany(User::class)
            ->withPivot('role')
            ->withTimestamps();
    }

    public function sites(): HasMany
    {
        return $this->hasMany(Site::class);
    }
}
