<?php

declare(strict_types=1);

namespace App\Services\RAG;

use App\Models\DocumentChunk;
use App\Support\CurrentTenant;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Top-K vector similarity search over embedded document chunks.
 *
 * Two execution paths, selected automatically by database driver:
 *
 *  - PostgreSQL with pgvector — uses the SQL operator `<=>` (cosine
 *    distance) against the `embedding_pg vector(768)` column populated by
 *    the trigger added in migration 2026_05_28_180100. With the HNSW index
 *    from migration 2026_05_28_180200, this scales to millions of chunks
 *    per tenant. Activated automatically when the migration has been run.
 *
 *  - SQLite / MySQL / PostgreSQL-without-pgvector — falls back to the
 *    in-memory PHP cosine pass. Fast enough for hundreds to low-thousands
 *    of chunks per tenant; this is what CI runs.
 *
 * The public contract — `topK(queryVector, k, ?siteId)` returning a
 * `list<{chunk_id, document_id, document_title, content, score}>` sorted
 * by descending similarity — is identical between paths. Sprint 7.3
 * callers (`QaService`) need no awareness of the optimization.
 *
 * Same posture as TimescaleDB hypertable conversion (ADR 0008): the
 * production PG optimization activates when the operator has installed
 * the extension and run the migrations; CI tests the portable path.
 */
final class VectorSearch
{
    /** Cached driver name so we don't re-query for every call. */
    private static ?string $driver = null;

    /** Cached column existence check for the pgvector mirror column. */
    private static ?bool $hasPgVectorColumn = null;

    /**
     * @param  list<float>  $queryVector
     * @return list<array{chunk_id: int, document_id: int, document_title: ?string, content: string, score: float}>
     */
    public function topK(array $queryVector, int $k = 5, ?int $siteId = null): array
    {
        if ($k <= 0 || $queryVector === []) {
            return [];
        }

        if ($this->canUsePgvector()) {
            return $this->topKPgvector($queryVector, $k, $siteId);
        }

        return $this->topKInMemory($queryVector, $k, $siteId);
    }

    /**
     * Cosine similarity between two vectors. Pure function; no DB.
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

    /**
     * Clear the driver + column existence cache. Called from tests when the
     * connection or schema changes between test cases.
     */
    public static function clearCache(): void
    {
        self::$driver = null;
        self::$hasPgVectorColumn = null;
    }

    // -----------------------------------------------------------------
    // Path A: pgvector (PostgreSQL with the Sprint 8.2 migrations applied)
    // -----------------------------------------------------------------

    /**
     * @param  list<float>  $queryVector
     * @return list<array{chunk_id: int, document_id: int, document_title: ?string, content: string, score: float}>
     */
    private function topKPgvector(array $queryVector, int $k, ?int $siteId): array
    {
        // pgvector wants `[0.1,0.2,...]` format with NO spaces.
        $vec = '['.implode(',', array_map(fn ($v) => (float) $v, $queryVector)).']';

        // The <=> operator returns cosine *distance* (1 - cosine similarity).
        // We compute `1 - distance` to keep the score semantically aligned
        // with the in-memory path (higher = more similar).
        $select = [
            'document_chunks.id as chunk_id',
            'document_chunks.document_id',
            'document_chunks.content',
            'documents.title as document_title',
            DB::raw('(1 - (document_chunks.embedding_pg <=> ?::vector)) as score'),
        ];

        $bindings = [$vec];

        $sql = DB::table('document_chunks')
            ->select($select)
            ->join('documents', 'documents.id', '=', 'document_chunks.document_id')
            ->whereNotNull('document_chunks.embedding_pg')
            ->where('document_chunks.tenant_id', CurrentTenant::id());

        if ($siteId !== null) {
            $sql->where('documents.site_id', $siteId);
        }

        $sql->orderByRaw('document_chunks.embedding_pg <=> ?::vector', [$vec])
            ->limit($k);

        // Prepend the SELECT-clause binding (the orderByRaw appends its own).
        $sql->addBinding($bindings, 'select');

        $rows = $sql->get();

        return $rows->map(fn ($r) => [
            'chunk_id' => (int) $r->chunk_id,
            'document_id' => (int) $r->document_id,
            'document_title' => $r->document_title,
            'content' => (string) $r->content,
            'score' => (float) $r->score,
        ])->all();
    }

    // -----------------------------------------------------------------
    // Path B: in-memory PHP cosine (SQLite, MySQL, or PG without pgvector)
    // -----------------------------------------------------------------

    /**
     * @param  list<float>  $queryVector
     * @return list<array{chunk_id: int, document_id: int, document_title: ?string, content: string, score: float}>
     */
    private function topKInMemory(array $queryVector, int $k, ?int $siteId): array
    {
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

    // -----------------------------------------------------------------
    // Driver detection
    // -----------------------------------------------------------------

    private function canUsePgvector(): bool
    {
        if (self::$driver === null) {
            self::$driver = Schema::getConnection()->getDriverName();
        }
        if (self::$driver !== 'pgsql') {
            return false;
        }

        if (self::$hasPgVectorColumn === null) {
            // hasColumn() is a cheap information_schema lookup; we cache
            // the result for the rest of the request lifecycle.
            self::$hasPgVectorColumn = Schema::hasColumn('document_chunks', 'embedding_pg');
        }

        return self::$hasPgVectorColumn;
    }
}
