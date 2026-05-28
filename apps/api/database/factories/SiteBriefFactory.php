<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Site;
use App\Models\SiteBrief;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<SiteBrief>
 */
class SiteBriefFactory extends Factory
{
    protected $model = SiteBrief::class;

    public function definition(): array
    {
        // tenant_id is set by the BelongsToTenant trait from CurrentTenant.
        return [
            'site_id' => Site::factory(),
            'summary' => fake()->paragraph(),
            'model' => 'claude-sonnet-4-5',
            'period_start' => now()->subDay(),
            'period_end' => now(),
            'metadata' => ['input_tokens' => 0, 'output_tokens' => 0],
            'generated_at' => now(),
        ];
    }

    public function forSite(Site $site): self
    {
        return $this->state(['site_id' => $site->id]);
    }
}
