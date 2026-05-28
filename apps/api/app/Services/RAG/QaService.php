<?php

declare(strict_types=1);

namespace App\Services\RAG;

use App\Enums\QuestionStatus;
use App\Models\Question;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Orchestrator for Site Q&A.
 *
 * Synchronous from the operator's perspective — they POST a question and
 * by the time the response lands the row is either Ready (answer + citations)
 * or Failed (error stamped). Worker round trips: one for embedding, one for
 * generation. Vector search is done in PHP via VectorSearch — fast for the
 * single-digit-thousand-chunks-per-tenant regime; swappable for pgvector at
 * deploy time without touching this code.
 *
 * The whole pipeline is wrapped in try/catch so the Question is never left
 * in Pending if anything throws.
 */
final class QaService
{
    public function __construct(
        private readonly WorkerRagClient $rag,
        private readonly VectorSearch $search,
        private readonly int $topK = 5,
    ) {}

    public function process(Question $question): Question
    {
        try {
            // 1. Embed the question text.
            $embed = $this->rag->embedQuery($question->question);
            $vector = $embed['embedding'];

            if ($vector === []) {
                throw new \RuntimeException('Worker returned an empty embedding vector.');
            }

            // 2. Top-K vector search, tenant-scoped (and site-scoped if asked).
            $chunks = $this->search->topK($vector, $this->topK, $question->site_id);

            // 3. If the knowledge base has nothing relevant, answer honestly
            //    rather than hallucinating context that doesn't exist.
            if ($chunks === []) {
                $question->forceFill([
                    'status' => QuestionStatus::Ready,
                    'answer' => 'No relevant context was found in the knowledge base for this question. Add documents on the /documents page and ask again.',
                    'model' => null,
                    'metadata' => [
                        'citations' => [],
                        'embedding_model' => $embed['model'],
                        'no_context' => true,
                    ],
                    'answered_at' => now(),
                ])->save();

                return $question;
            }

            // 4. Hand the contexts to the LLM via the worker.
            $contexts = array_map(
                fn (array $c): array => [
                    'content' => $c['content'],
                    'document_title' => $c['document_title'],
                    'score' => $c['score'],
                ],
                $chunks,
            );

            $result = $this->rag->answer($question->question, $contexts);

            // 5. Persist the answer + structured citations.
            $citations = array_map(
                fn (array $c): array => [
                    'chunk_id' => $c['chunk_id'],
                    'document_id' => $c['document_id'],
                    'document_title' => $c['document_title'],
                    'score' => $c['score'],
                ],
                $chunks,
            );

            $question->forceFill([
                'status' => QuestionStatus::Ready,
                'answer' => $result['answer'],
                'model' => $result['model'],
                'metadata' => [
                    'citations' => $citations,
                    'embedding_model' => $embed['model'],
                    'generation' => $result['metadata'],
                ],
                'answered_at' => now(),
            ])->save();

            return $question;
        } catch (Throwable $e) {
            Log::warning('QaService failed', [
                'question_id' => $question->id,
                'exception' => $e::class,
                'message' => $e->getMessage(),
            ]);

            $question->forceFill([
                'status' => QuestionStatus::Failed,
                'error' => sprintf('Q&A pipeline failed: %s', $e->getMessage()),
            ])->save();

            return $question;
        }
    }
}
