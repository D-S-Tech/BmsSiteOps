<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Enums\SourceKind;
use App\Enums\SourceStatus;
use App\Models\Site;
use App\Models\Source;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<Source>
 */
class SourceFactory extends Factory
{
    protected $model = Source::class;

    public function definition(): array
    {
        $kind = fake()->randomElement(SourceKind::cases());

        return [
            // tenant_id is set by the BelongsToTenant trait from CurrentTenant.
            'site_id' => Site::factory(),
            'kind' => $kind,
            'name' => $kind->label().' — '.fake()->company(),
            'base_url' => 'https://'.fake()->domainName(),
            'credentials' => ['api_token' => Str::random(40)],
            'poll_interval_seconds' => 60,
            'is_active' => true,
            'last_status' => SourceStatus::Never,
            'metadata' => [],
        ];
    }

    public function forSite(Site $site): self
    {
        return $this->state(['site_id' => $site->id]);
    }

    public function kind(SourceKind $kind): self
    {
        return $this->state([
            'kind' => $kind,
            'name' => $kind->label().' — '.fake()->company(),
        ]);
    }

    public function inactive(): self
    {
        return $this->state(['is_active' => false]);
    }
}
