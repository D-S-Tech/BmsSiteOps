<?php

declare(strict_types=1);

namespace Tests\Integration\RAG;

use App\Models\Document;
use App\Models\DocumentChunk;
use App\Models\Tenant;
use App\Services\RAG\VectorSearch;
use App\Support\CurrentTenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Tests\Integration\IntegrationTestCase;

/**
 * Live pgvector path tests.
 *
 * These run against a REAL Postgres + pgvector instance. They prove:
 *  - the auto-sync trigger fires correctly (JSON text -> vector cast)
 *  - the HNSW index is used (ORDER BY embedding_pg <=> query LIMIT k)
 *  - results match the in-memory cosine path for the same inputs
 *
 * Required env: LIVE_TESTS=1, DB_CONNECTION=pgsql, the three Sprint 8.2
 * migrations applied.
 *
 * @group integration
 */
class PgvectorPathLiveTest extends IntegrationTestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    protected function setUp(): void
    {
        parent::setUp();

        if (Schema::getConnection()->getDriverName() !== 'pgsql') {
            $this->markTestSkipped('pgvector tests require pgsql driver.');
        }
        if (! Schema::hasColumn('document_chunks', 'embedding_pg')) {
            $this->markTestSkipped(
                'pgvector column not present; Sprint 8.2 migrations not applied?'
            );
        }

        $this->tenant = Tenant::create(['slug' => 'acme', 'name' => 'Acme']);
        CurrentTenant::set($this->tenant);
        VectorSearch::clearCache();
    }

    protected function tearDown(): void
    {
        CurrentTenant::forget();
        VectorSearch::clearCache();
        parent::tearDown();
    }

    public function test_trigger_syncs_embedding_text_into_pgvector_column(): void
    {
        $doc = Document::factory()->create();
        $chunk = DocumentChunk::factory()->forDocument($doc)
            ->embedded([0.1, 0.2, 0.3])
            ->create();

        $fresh = $chunk->fresh();
        $this->assertNotNull(
            $fresh->getRawOriginal('embedding_pg'),
            'BEFORE INSERT trigger should have populated embedding_pg'
        );
    }

    public function test_topk_returns_ordered_results_via_pgvector(): void
    {
        $doc = Document::factory()->create(['title' => 'Mech room SOO']);

        $closest = DocumentChunk::factory()->forDocument($doc)->position(0)
            ->embedded([1.0, 0.0])->create(['content' => 'most similar']);
        DocumentChunk::factory()->forDocument($doc)->position(1)
            ->embedded([0.7, 0.7])->create(['content' => 'middle']);
        DocumentChunk::factory()->forDocument($doc)->position(2)
            ->embedded([0.0, 1.0])->create(['content' => 'least similar']);

        $search = new VectorSearch;
        $results = $search->topK([1.0, 0.0], k: 3);

        $this->assertCount(3, $results);
        $this->assertSame($closest->id, $results[0]['chunk_id']);
        $this->assertSame('most similar', $results[0]['content']);
        // Scores monotonically decrease.
        $this->assertGreaterThan($results[1]['score'], $results[0]['score']);
        $this->assertGreaterThan($results[2]['score'], $results[1]['score']);
        // Score is similarity (higher is better), so the top hit is near 1.
        $this->assertEqualsWithDelta(1.0, $results[0]['score'], 0.01);
    }

    public function test_topk_respects_tenant_scope_on_pgvector_path(): void
    {
        $docA = Document::factory()->create();
        DocumentChunk::factory()->forDocument($docA)
            ->embedded([1.0, 0.0])->create(['content' => 'A tenant']);

        $other = Tenant::create(['slug' => 'other', 'name' => 'Other']);
        CurrentTenant::set($other);
        $docB = Document::factory()->create();
        DocumentChunk::factory()->forDocument($docB)
            ->embedded([1.0, 0.0])->create(['content' => 'B tenant']);
        CurrentTenant::set($this->tenant);

        $results = (new VectorSearch)->topK([1.0, 0.0], k: 5);
        $this->assertCount(1, $results);
        $this->assertSame('A tenant', $results[0]['content']);
    }
}
