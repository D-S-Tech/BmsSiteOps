<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\McpAuditEntry;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<McpAuditEntry>
 */
class McpAuditEntryFactory extends Factory
{
    protected $model = McpAuditEntry::class;

    public function definition(): array
    {
        $tool = fake()->randomElement([
            'bmssiteops_list_sites',
            'bmssiteops_site_overview',
            'bmssiteops_ask',
            'bmssiteops_create_script',
        ]);

        return [
            // tenant_id is set by the BelongsToTenant trait from CurrentTenant.
            'user_id' => User::factory(),
            'tool_name' => $tool,
            'method' => match ($tool) {
                'bmssiteops_list_sites', 'bmssiteops_site_overview' => 'GET',
                default => 'POST',
            },
            'path' => match ($tool) {
                'bmssiteops_list_sites' => 'api/v1/sites',
                'bmssiteops_site_overview' => 'api/v1/sites/'.fake()->numberBetween(1, 9).'/summary',
                'bmssiteops_ask' => 'api/v1/qa',
                'bmssiteops_create_script' => 'api/v1/scripts',
            },
            'request_payload' => match ($tool) {
                'bmssiteops_ask' => ['question' => fake()->sentence().'?'],
                'bmssiteops_create_script' => [
                    'title' => fake()->words(3, true),
                    'prompt' => fake()->sentence(),
                    'language' => 'python',
                ],
                default => null,
            },
            'response_status' => 200,
            'duration_ms' => fake()->numberBetween(30, 2500),
            'ip_address' => fake()->ipv4(),
        ];
    }

    public function failed(): self
    {
        return $this->state(['response_status' => fake()->randomElement([400, 422, 500])]);
    }

    public function slow(): self
    {
        return $this->state(['duration_ms' => fake()->numberBetween(1500, 12000)]);
    }
}
