<?php

declare(strict_types=1);

namespace App\Services\RAG;

use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Http;

/**
 * Real WorkerRagClient — HTTP-backed, HMAC-signed.
 *
 * Outbound flow (Laravel -> Python worker):
 *   POST /qa/embed   { text, model? }
 *   POST /qa/answer  { question, contexts, model? }
 *
 * The worker endpoints (Sprint 7.4) verify the X-WORKER-TIMESTAMP /
 * X-WORKER-SIGNATURE HMAC headers with the shared internal key — exactly
 * the same scheme the worker uses inbound to Laravel, just reversed.
 *
 * Live request shape is unit-tested via Http::fake() (request URL, headers,
 * body). The live round trip against a running worker is integration-only
 * and validated by hand once 7.4 is deployed — same posture as the LLM,
 * embedding, and TRMM transport notes.
 */
final class HttpWorkerRagClient implements WorkerRagClient
{
    public function __construct(
        private readonly string $baseUrl,
        private readonly string $internalKey,
        private readonly int $timeoutSeconds = 120,
    ) {}

    public function embedQuery(string $text): array
    {
        $payload = ['text' => $text];
        $response = $this->sign($payload)->post($this->url('/qa/embed'), $payload);

        $response->throwIfClientError()->throwIfServerError();
        $body = (array) $response->json();

        return [
            'embedding' => (array) ($body['embedding'] ?? []),
            'model' => (string) ($body['model'] ?? ''),
        ];
    }

    public function answer(string $question, array $contexts, ?string $model = null): array
    {
        $payload = [
            'question' => $question,
            'contexts' => $contexts,
        ];
        if ($model !== null) {
            $payload['model'] = $model;
        }

        $response = $this->sign($payload)->post($this->url('/qa/answer'), $payload);
        $response->throwIfClientError()->throwIfServerError();
        $body = (array) $response->json();

        return [
            'answer' => (string) ($body['answer'] ?? ''),
            'model' => (string) ($body['model'] ?? ($model ?? '')),
            'metadata' => (array) ($body['metadata'] ?? []),
        ];
    }

    /**
     * Builds a PendingRequest with HMAC signing headers. Body is the
     * JSON-encoded payload (must match what Http::post will send).
     *
     * @param  array<string, mixed>  $payload
     */
    private function sign(array $payload): PendingRequest
    {
        $body = json_encode($payload, JSON_THROW_ON_ERROR);
        $timestamp = (string) time();
        $signature = hash_hmac('sha256', $timestamp.'.'.$body, $this->internalKey);

        return Http::timeout($this->timeoutSeconds)
            ->acceptJson()
            ->asJson()
            ->withHeaders([
                'X-Worker-Timestamp' => $timestamp,
                'X-Worker-Signature' => $signature,
            ]);
    }

    private function url(string $path): string
    {
        return rtrim($this->baseUrl, '/').$path;
    }
}
