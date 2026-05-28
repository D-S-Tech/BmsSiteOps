<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Enums\ScriptLanguage;
use App\Enums\ScriptStatus;
use App\Models\Script;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Script>
 */
class ScriptFactory extends Factory
{
    protected $model = Script::class;

    public function definition(): array
    {
        // tenant_id is set by the BelongsToTenant trait from CurrentTenant.
        return [
            'requested_by_user_id' => null,
            'title' => fake()->sentence(4),
            'prompt' => fake()->paragraph(),
            'language' => ScriptLanguage::Python,
            'status' => ScriptStatus::Requested,
            'content' => null,
            'model' => null,
            'error' => null,
            'metadata' => null,
            'requested_at' => now(),
            'claimed_at' => null,
            'generated_at' => null,
        ];
    }

    public function language(ScriptLanguage $language): self
    {
        return $this->state(['language' => $language]);
    }

    public function status(ScriptStatus $status): self
    {
        return $this->state(['status' => $status]);
    }

    public function ready(string $content = "print('hello, world')"): self
    {
        return $this->state([
            'status' => ScriptStatus::Ready,
            'content' => $content,
            'model' => 'ollama/qwen2.5-coder:32b',
            'claimed_at' => now()->subMinute(),
            'generated_at' => now(),
        ]);
    }

    public function failed(string $error = 'Model unreachable'): self
    {
        return $this->state([
            'status' => ScriptStatus::Failed,
            'error' => $error,
            'claimed_at' => now()->subMinute(),
            'generated_at' => now(),
        ]);
    }
}
