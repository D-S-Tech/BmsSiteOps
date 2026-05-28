<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Enums\QuestionStatus;
use App\Models\Question;
use App\Models\Site;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Question>
 */
class QuestionFactory extends Factory
{
    protected $model = Question::class;

    public function definition(): array
    {
        // tenant_id stamped by the BelongsToTenant trait from CurrentTenant.
        return [
            'site_id' => null,
            'asked_by_user_id' => null,
            'question' => fake()->sentence().'?',
            'answer' => null,
            'status' => QuestionStatus::Pending,
            'error' => null,
            'model' => null,
            'metadata' => null,
            'asked_at' => now(),
            'answered_at' => null,
        ];
    }

    public function ready(string $answer = 'The system is running normally.'): self
    {
        return $this->state([
            'status' => QuestionStatus::Ready,
            'answer' => $answer,
            'model' => 'claude-sonnet-4-5',
            'metadata' => ['citations' => [], 'embedding_model' => 'ollama/nomic-embed-text'],
            'answered_at' => now(),
        ]);
    }

    public function failed(string $error = 'Worker /qa/embed returned HTTP 503'): self
    {
        return $this->state([
            'status' => QuestionStatus::Failed,
            'error' => $error,
        ]);
    }

    public function forSite(Site $site): self
    {
        return $this->state(['site_id' => $site->id]);
    }
}
