<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Create an HNSW index on document_chunks.embedding_pg for fast approximate
 * nearest-neighbor search.
 *
 * pgvector supports two index types:
 *   - HNSW    (Hierarchical Navigable Small World) — better recall + faster
 *             queries, slower to build, larger on disk
 *   - IVFFlat (inverted file flat) — faster to build, smaller, slightly
 *             worse recall
 *
 * For BmsSiteOps's expected scale (hundreds to low-thousands of chunks per
 * tenant), HNSW with default parameters is appropriate. The
 * `vector_cosine_ops` operator class uses the <=> distance operator
 * (cosine), matching the cosineSimilarity semantics VectorSearch already
 * uses in the SQLite path.
 *
 * Tuning knobs (left at defaults for now):
 *   - m              (default 16)  graph connections per layer
 *   - ef_construction (default 64) build-time search list size
 *
 * For workloads above ~10k chunks per tenant, consider raising m to 32 and
 * ef_construction to 128. The index is dropped + rebuilt by the operator,
 * not by a migration.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! $this->isPostgres()) {
            return;
        }

        DB::statement(<<<'SQL'
            CREATE INDEX IF NOT EXISTS document_chunks_embedding_pg_hnsw_idx
            ON document_chunks
            USING hnsw (embedding_pg vector_cosine_ops)
        SQL);
    }

    public function down(): void
    {
        if (! $this->isPostgres()) {
            return;
        }
        DB::statement('DROP INDEX IF EXISTS document_chunks_embedding_pg_hnsw_idx');
    }

    private function isPostgres(): bool
    {
        return Schema::getConnection()->getDriverName() === 'pgsql';
    }
};
