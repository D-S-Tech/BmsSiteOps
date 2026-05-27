<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Site;
use App\Models\Tenant;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<Site>
 */
class SiteFactory extends Factory
{
    protected $model = Site::class;

    public function definition(): array
    {
        $name = fake()->company().' HQ';

        return [
            // tenant_id is set by the BelongsToTenant trait from CurrentTenant.
            // Tests that need an explicit tenant override should pass tenant_id.
            'slug' => Str::slug($name).'-'.Str::random(4),
            'name' => $name,
            'address' => fake()->streetAddress().', '.fake()->city().', '.fake()->stateAbbr().' '.fake()->postcode(),
            'timezone' => 'America/New_York',
            'metadata' => [],
        ];
    }

    /**
     * Override tenant — useful for tests that need a site in a specific tenant
     * without messing with CurrentTenant state.
     */
    public function forTenant(Tenant|int $tenant): self
    {
        $tenantId = $tenant instanceof Tenant ? $tenant->id : $tenant;

        return $this->state(['tenant_id' => $tenantId]);
    }
}
