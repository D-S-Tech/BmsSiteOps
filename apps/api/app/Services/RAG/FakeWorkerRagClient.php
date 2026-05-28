<?php

declare(strict_types=1);

namespace App\Services\RAG;

/**
 * In-memory WorkerRagClient for tests and local development.
 *
 * Returns canned answers, records every call, can be configured to throw
 * on any operation. Mirror of FakeLLMClient and FakeEmbeddingClient — keeps
 * QaService fully unit-testable without a running worker.
 */
final class FakeWorkerRagClient implements WorkerRagClient
{
    /** @var list<float> */
    private array $embedding;

    private string $embeddingModel;

    private string $answer;

    private string $answerModel;

    /** @var array<string, mixed> */
    private array $answerMetadata;

    private ?\Throwable $embedThrows = null;

    private ?\Throwable $answerThrows = null;

    /** @var list<array{op: string, args: array<string, mixed>}> */
    public array $calls = [];

    public function __construct(
        ?array $embedding = null,
        string $embeddingModel = 'ollama/nomic-embed-text',
        string $answer = 'Canned test answer.',
        string $answerModel = 'claude-sonnet-4-5',
        array $answerMetadata = ['input_tokens' => 100, 'output_tokens' => 50],
    ) {
        $this->embedding = $embedding ?? [0.1, 0.2, 0.3, 0.4, 0.5, 0.6, 0.7, 0.8];
        $this->embeddingModel = $embeddingModel;
        $this->answer = $answer;
        $this->answerModel = $answerModel;
        $this->answerMetadata = $answerMetadata;
    }

    public function throwOnEmbed(\Throwable $e): self
    {
        $this->embedThrows = $e;

        return $this;
    }

    public function throwOnAnswer(\Throwable $e): self
    {
        $this->answerThrows = $e;

        return $this;
    }

    public function setAnswer(string $answer): self
    {
        $this->answer = $answer;

        return $this;
    }

    /** @param  list<float>  $vector */
    public function setEmbedding(array $vector): self
    {
        $this->embedding = $vector;

        return $this;
    }

    public function embedQuery(string $text): array
    {
        $this->calls[] = ['op' => 'embedQuery', 'args' => ['text' => $text]];
        if ($this->embedThrows !== null) {
            throw $this->embedThrows;
        }

        return ['embedding' => $this->embedding, 'model' => $this->embeddingModel];
    }

    public function answer(string $question, array $contexts, ?string $model = null): array
    {
        $this->calls[] = [
            'op' => 'answer',
            'args' => ['question' => $question, 'contexts' => $contexts, 'model' => $model],
        ];
        if ($this->answerThrows !== null) {
            throw $this->answerThrows;
        }

        return [
            'answer' => $this->answer,
            'model' => $model ?? $this->answerModel,
            'metadata' => $this->answerMetadata,
        ];
    }
}
