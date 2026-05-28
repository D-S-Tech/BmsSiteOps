<?php

declare(strict_types=1);

namespace Tests\Feature\RAG;

use App\Models\Document;
use App\Models\DocumentChunk;
use App\Models\Site;
use App\Models\Tenant;
use App\Services\RAG\VectorSearch;
use App\Support\CurrentTenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Feature tests for VectorSearch.topK — DB-backed.
 *
 * Vectors are intentionally short (2-3 dims) so we can predict cosine
 * similarities by hand. The point of these tests isn't to validate the
 * cosine math — that's covered in VectorSearchCosineTest — but to verify
 * tenant scoping, site filtering, null-embedding skipping, ordering, and
 * the k limit.
 */
class VectorSearchTopKTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    private VectorSearch $search;

    protected function setUp(): void
    {
        parent::setUp();
        $this->tenant = Tenant::create(['slug' => 'acme', 'name' => 'Acme']);
        CurrentTenant::set($this->tenant);
        $this->search = new VectorSearch;
    }

    protected function tearDown(): void
    {
        CurrentTenant::forget();
        parent::tearDown();
    }

    public function test_returns_chunks_in_descending_score_order(): void
    {
        $doc = Document::factory()->create(['title' => 'SOO']);

        // Three chunks, each with a different embedding.
        DocumentChunk::factory()->forDocument($doc)->position(0)
            ->embedded([1.0, 0.0])->create(['content' => 'most similar']);
        DocumentChunk::factory()->forDocument($doc)->position(1)
            ->embedded([0.7, 0.7])->create(['content' => 'middle']);
        DocumentChunk::factory()->forDocument($doc)->position(2)
            ->embedded([0.0, 1.0])->create(['content' => 'least similar']);

        $results = $this->search->topK([1.0, 0.0], k: 3);

        $this->assertCount(3, $results);
        $this->assertSame('most similar', $results[0]['content']);
        $this->assertSame('middle', $results[1]['content']);
        $this->assertSame('least similar', $results[2]['content']);

        // Scores monotonically decrease.
        $this->assertGreaterThan($results[1]['score'], $results[0]['score']);
        $this->assertGreaterThan($results[2]['score'], $results[1]['score']);
    }

    public function test_respects_the_k_limit(): void
    {
        $doc = Document::factory()->create();
        for ($i = 0; $i < 6; $i++) {
            DocumentChunk::factory()->forDocument($doc)->position($i)
                ->embedded([1.0 - 0.1 * $i, 0.1 * $i])->create();
        }

        $results = $this->search->topK([1.0, 0.0], k: 3);
        $this->assertCount(3, $results);
    }

    public function test_zero_or_negative_k_returns_empty(): void
    {
        $doc = Document::factory()->create();
        DocumentChunk::factory()->forDocument($doc)->embedded()->create();

        $this->assertSame([], $this->search->topK([1.0, 0.0], k: 0));
        $this->assertSame([], $this->search->topK([1.0, 0.0], k: -5));
    }

    public function test_empty_query_vector_returns_empty(): void
    {
        $doc = Document::factory()->create();
        DocumentChunk::factory()->forDocument($doc)->embedded()->create();

        $this->assertSame([], $this->search->topK([], k: 5));
    }

    public function test_skips_chunks_without_embedding(): void
    {
        $doc = Document::factory()->create();
        DocumentChunk::factory()->forDocument($doc)->position(0)->create();  // no embedding
        DocumentChunk::factory()->forDocument($doc)->position(1)
            ->embedded([1.0, 0.0])->create(['content' => 'only one']);

        $results = $this->search->topK([1.0, 0.0], k: 5);

        $this->assertCount(1, $results);
        $this->assertSame('only one', $results[0]['content']);
    }

    public function test_filters_by_site_id_when_provided(): void
    {
        $siteA = Site::factory()->create(['slug' => 'site-a']);
        $siteB = Site::factory()->create(['slug' => 'site-b']);

        $docA = Document::factory()->forSite($siteA)->create();
        $docB = Document::factory()->forSite($siteB)->create();

        DocumentChunk::factory()->forDocument($docA)
            ->embedded([1.0, 0.0])->create(['content' => 'A chunk']);
        DocumentChunk::factory()->forDocument($docB)
            ->embedded([1.0, 0.0])->create(['content' => 'B chunk']);

        $results = $this->search->topK([1.0, 0.0], k: 5, siteId: $siteA->id);

        $this->assertCount(1, $results);
        $this->assertSame('A chunk', $results[0]['content']);
        $this->assertSame($docA->id, $results[0]['document_id']);
    }

    public function test_is_tenant_scoped_automatically(): void
    {
        // Tenant A chunk (current).
        $docA = Document::factory()->create();
        DocumentChunk::factory()->forDocument($docA)
            ->embedded([1.0, 0.0])->create(['content' => 'A tenant chunk']);

        // Tenant B chunk — switch context, create, switch back.
        $other = Tenant::create(['slug' => 'other', 'name' => 'Other']);
        CurrentTenant::set($other);
        $docB = Document::factory()->create();
        DocumentChunk::factory()->forDocument($docB)
            ->embedded([1.0, 0.0])->create(['content' => 'B tenant chunk']);
        CurrentTenant::set($this->tenant);

        $results = $this->search->topK([1.0, 0.0], k: 5);

        $this->assertCount(1, $results);
        $this->assertSame('A tenant chunk', $results[0]['content']);
    }

    public function test_result_includes_document_title_and_chunk_metadata(): void
    {
        $doc = Document::factory()->create(['title' => 'Mech room SOO']);
        $chunk = DocumentChunk::factory()->forDocument($doc)->position(7)
            ->embedded([1.0, 0.0])->create(['content' => 'AHU-1 controls']);

        $results = $this->search->topK([1.0, 0.0], k: 1);

        $this->assertSame($chunk->id, $results[0]['chunk_id']);
        $this->assertSame($doc->id, $results[0]['document_id']);
        $this->assertSame('Mech room SOO', $results[0]['document_title']);
        $this->assertSame('AHU-1 controls', $results[0]['content']);
    }
}
