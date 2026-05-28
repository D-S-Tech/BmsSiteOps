<?php

declare(strict_types=1);

namespace App\Services\RAG;

/**
 * Contract for the Laravel -> Python worker RAG transport.
 *
 *   * embedQuery — embed the operator's question; the worker proxies to
 *     LiteLLM (default model: ollama/nomic-embed-text on BOLDNJPC). The
 *     resulting vector is what we feed to VectorSearch.
 *
 *   * answer    — given the question plus the top-K context chunks,
 *     generate an answer. The worker proxies to whichever LLM the
 *     deployment is wired to (default: claude-sonnet-4-5 for highest
 *     quality; switchable to ollama/qwen2.5-coder:32b for fully local).
 *
 * The interface lets QaService remain transport-agnostic. Tests use
 * FakeWorkerRagClient; production wires HttpWorkerRagClient — same seam
 * pattern as LLMClient (Sprint 4) and EmbeddingClient (Sprint 7.2).
 */
interface WorkerRagClient
{
    /**
     * Embed a single piece of text (typically the operator's question).
     *
     * @return array{embedding: list<float>, model: string}
     */
    public function embedQuery(string $text): array;

    /**
     * Answer the question given retrieved context.
     *
     * @param  list<array{content: string, document_title?: ?string, score?: float}>  $contexts
     * @return array{answer: string, model: string, metadata: array<string, mixed>}
     */
    public function answer(string $question, array $contexts, ?string $model = null): array;
}
