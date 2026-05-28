<?php

declare(strict_types=1);

namespace App\Services\RAG;

use App\Models\DocumentChunk;

/**
 * Top-K vector similarity search over embedded document chunks.
 *
 * The PHP implementation loads chunks (tenant-scoped automatically via the
 * BelongsToTenant trait's global scope) and computes cosine similarity in
 * memory. This is fast enough for hundreds to low-thousands of chunks per
 * tenant — the realistic ceiling for a multi-site HVAC/MEP contractor.
 *
 * A future deployment-time optimization on PostgreSQL is to install pgvector
 * and migrate the document_chunks.embedding column from TEXT (JSON-serialized
 * floats) to vector(N), then replace the cosineSimilarity() pass with a SQL
 * ORDER BY embedding <-> query LIMIT k. The public contract of this service
 * (queryVector, k, optional siteId) stays the same — same posture as the
 * TimescaleDB hypertable conversion noted in ADR 0008.
 */
final class VectorSearch
{
    /**
     * Return the top-K chunks ordered by cosine similarity desc.
     *
     * Result shape per element:
     *   {
     *     chunk_id:        int,
     *     document_id:     int,
     *     document_title:  ?string,
     *     content:         string,
     *     score:           float (cosine similarity, [-1, 1])
     *   }
     *
     * @param  list<float>  $queryVector
     * @return list<array{chunk_id: int, document_id: int, document_title: ?string, content: string, score: float}>
     */
    public function topK(array $queryVector, int $k = 5, ?int $siteId = null): array
    {
        if ($k <= 0 || $queryVector === []) {
            return [];
        }

        $query = DocumentChunk::query()
            ->whereNotNull('embedding')
            ->with('document:id,title,site_id');

        if ($siteId !== null) {
            $query->whereHas('document', fn ($q) => $q->where('site_id', $siteId));
        }

        $scored = [];
        foreach ($query->lazy(200) as $chunk) {
            /** @var DocumentChunk $chunk */
            $embedding = $chunk->embedding;
            if (! is_array($embedding) || $embedding === []) {
                continue;
            }

            $score = $this->cosineSimilarity($queryVector, $embedding);
            $scored[] = [
                'chunk_id' => $chunk->id,
                'document_id' => $chunk->document_id,
                'document_title' => $chunk->document?->title,
                'content' => $chunk->content,
                'score' => $score,
            ];
        }

        usort($scored, static fn (array $a, array $b) => $b['score'] <=> $a['score']);

        return array_slice($scored, 0, $k);
    }

    /**
     * Cosine similarity between two vectors. If lengths differ we use the
     * common prefix — this protects us when an upstream embedding model is
     * changed and old chunks haven't been re-embedded yet (in that case
     * scores will be junk but we don't crash; the operator will notice and
     * re-embed).
     *
     * Returns 0.0 for any pair where at least one vector is all-zero,
     * matching the convention that a zero vector has no defined direction.
     *
     * @param  list<float>  $a
     * @param  list<float>  $b
     */
    public function cosineSimilarity(array $a, array $b): float
    {
        $n = min(count($a), count($b));
        if ($n === 0) {
            return 0.0;
        }

        $dot = 0.0;
        $normA = 0.0;
        $normB = 0.0;

        for ($i = 0; $i < $n; $i++) {
            $av = (float) $a[$i];
            $bv = (float) $b[$i];
            $dot += $av * $bv;
            $normA += $av * $av;
            $normB += $bv * $bv;
        }

        if ($normA == 0.0 || $normB == 0.0) {
            return 0.0;
        }

        return $dot / (sqrt($normA) * sqrt($normB));
    }
}
