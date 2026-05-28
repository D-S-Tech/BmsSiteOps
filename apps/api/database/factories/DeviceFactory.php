<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Enums\DeviceStatus;
use App\Models\Device;
use App\Models\Source;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<Device>
 */
class DeviceFactory extends Factory
{
    protected $model = Device::class;

    public function definition(): array
    {
        return [
            // tenant_id is set by the BelongsToTenant trait from CurrentTenant.
            'source_id' => Source::factory(),
            // site_id must match the parent source's site — derive it once the
            // source_id has been resolved to a concrete id.
            'site_id' => fn (array $attrs) => Source::withoutGlobalScopes()
                ->findOrFail($attrs['source_id'])->site_id,
            'external_id' => (string) Str::uuid(),
            'name' => fake()->word().'-'.fake()->numberBetween(1, 99),
            'type' => fake()->randomElement(['server', 'workstation', 'controller', 'sensor']),
            'status' => DeviceStatus::Online,
            'last_seen_at' => now(),
            'metadata' => [],
        ];
    }

    /**
     * Attach to an explicit source, inheriting its site_id (and tenant).
     */
    public function forSource(Source $source): self
    {
        return $this->state([
            'source_id' => $source->id,
            'site_id' => $source->site_id,
        ]);
    }

    public function status(DeviceStatus $status): self
    {
        return $this->state(['status' => $status]);
    }
}
