<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Enums\EventSeverity;
use App\Models\Device;
use App\Models\Event;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Event>
 */
class EventFactory extends Factory
{
    protected $model = Event::class;

    public function definition(): array
    {
        return [
            // tenant_id is set by the BelongsToTenant trait from CurrentTenant.
            'device_id' => Device::factory(),
            // source_id, site_id, and kind are derived from the parent device
            // once device_id is resolved, keeping the denormalized columns
            // consistent with the device's source.
            'source_id' => fn (array $attrs) => $this->device($attrs)->source_id,
            'site_id' => fn (array $attrs) => $this->device($attrs)->site_id,
            'kind' => fn (array $attrs) => $this->device($attrs)->source->kind,
            'metric' => fake()->randomElement(['cpu_load', 'memory_used', 'alert', 'discharge_temp']),
            'value' => (string) fake()->numberBetween(0, 100),
            'severity' => fake()->randomElement(EventSeverity::cases()),
            'occurred_at' => now(),
            'metadata' => [],
        ];
    }

    private function device(array $attrs): Device
    {
        return Device::withoutGlobalScopes()
            ->with('source')
            ->findOrFail($attrs['device_id']);
    }

    /**
     * Attach to an explicit device, inheriting its source/site/kind.
     */
    public function forDevice(Device $device): self
    {
        return $this->state([
            'device_id' => $device->id,
            'source_id' => $device->source_id,
            'site_id' => $device->site_id,
            'kind' => $device->source->kind,
        ]);
    }

    public function severity(EventSeverity $severity): self
    {
        return $this->state(['severity' => $severity]);
    }

    public function metric(string $metric, string $value): self
    {
        return $this->state(['metric' => $metric, 'value' => $value]);
    }
}
