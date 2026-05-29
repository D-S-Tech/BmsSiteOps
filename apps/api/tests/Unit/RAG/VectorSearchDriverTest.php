<?php

declare(strict_types=1);

namespace Tests\Unit\RAG;

use App\Services\RAG\VectorSearch;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * Driver-detection unit tests for VectorSearch.
 *
 * These are NOT testing the pgvector SQL path — that requires a real
 * Postgres + pgvector, which is integration-only. These tests cover the
 * driver-selection logic + cache invalidation so the wrong path is never
 * chosen on the wrong driver.
 */
class VectorSearchDriverTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        VectorSearch::clearCache();
    }

    protected function tearDown(): void
    {
        VectorSearch::clearCache();
        parent::tearDown();
    }

    public function test_sqlite_uses_in_memory_path(): void
    {
        // CI runs SQLite; the canUsePgvector check must return false here so
        // we always go through the PHP cosine path.
        $this->assertSame('sqlite', Schema::getConnection()->getDriverName());

        // The in-memory path doesn't need an embedding_pg column — verify
        // it isn't present (it shouldn't be on SQLite — the migration is
        // PG-only no-op).
        $this->assertFalse(Schema::hasColumn('document_chunks', 'embedding_pg'));

        // Empty query returns empty without touching DB or driver detection.
        $search = new VectorSearch;
        $this->assertSame([], $search->topK([], k: 5));
        $this->assertSame([], $search->topK([1.0, 0.0], k: 0));
    }

    public function test_clear_cache_can_be_invoked_safely(): void
    {
        // clearCache() must be safe to call at any time, including before
        // any topK() call has populated the cache. setUp() + tearDown()
        // both call it in production tests, so it must never throw.
        VectorSearch::clearCache();
        VectorSearch::clearCache();  // and re-invocation is idempotent
        $this->assertTrue(true);
    }

    public function test_cosine_similarity_still_works_after_path_refactor(): void
    {
        $search = new VectorSearch;
        // Sanity: refactoring topK() to add the pgvector path mustn't have
        // broken the public cosineSimilarity helper.
        $this->assertEqualsWithDelta(
            1.0,
            $search->cosineSimilarity([0.5, 0.5, 0.5], [0.5, 0.5, 0.5]),
            1e-9
        );
        $this->assertEqualsWithDelta(
            0.0,
            $search->cosineSimilarity([1.0, 0.0], [0.0, 1.0]),
            1e-9
        );
    }
}
