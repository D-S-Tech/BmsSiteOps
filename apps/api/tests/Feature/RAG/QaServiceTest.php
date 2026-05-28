<?php

declare(strict_types=1);

namespace Tests\Feature\RAG;

use App\Enums\QuestionStatus;
use App\Models\Document;
use App\Models\DocumentChunk;
use App\Models\Question;
use App\Models\Site;
use App\Models\Tenant;
use App\Services\RAG\FakeWorkerRagClient;
use App\Services\RAG\QaService;
use App\Services\RAG\VectorSearch;
use App\Support\CurrentTenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * QaService orchestrates: embed -> search -> generate -> persist.
 *
 * We use the real VectorSearch (DB-backed) with FakeWorkerRagClient so we
 * can validate the full happy path / no-context path / failure paths
 * deterministically.
 */
class QaServiceTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    protected function setUp(): void
    {
        parent::setUp();
        $this->tenant = Tenant::create(['slug' => 'acme', 'name' => 'Acme']);
        CurrentTenant::set($this->tenant);
    }

    protected function tearDown(): void
    {
        CurrentTenant::forget();
        parent::tearDown();
    }

    public function test_happy_path_persists_ready_with_answer_and_citations(): void
    {
        // Seed two chunks: one closer to [1,0], one far away.
        $doc = Document::factory()->create(['title' => 'Mech room SOO']);
        $chunk1 = DocumentChunk::factory()->forDocument($doc)->position(0)
            ->embedded([1.0, 0.0])->create(['content' => 'AHU-1 sequence.']);
        DocumentChunk::factory()->forDocument($doc)->position(1)
            ->embedded([0.0, 1.0])->create(['content' => 'Unrelated content.']);

        $fake = new FakeWorkerRagClient(
            embedding: [1.0, 0.0],
            answer: 'AHU-1 starts when OAT > 55F.',
        );

        $service = new QaService($fake, new VectorSearch);

        $question = Question::create([
            'question' => 'When does AHU-1 start?',
            'status' => QuestionStatus::Pending,
            'asked_at' => now(),
        ]);

        $result = $service->process($question);

        $this->assertSame(QuestionStatus::Ready, $result->status);
        $this->assertSame('AHU-1 starts when OAT > 55F.', $result->answer);
        $this->assertSame('claude-sonnet-4-5', $result->model);
        $this->assertNotNull($result->answered_at);

        // Citations: at least the closest chunk made it in.
        $citations = $result->metadata['citations'] ?? [];
        $this->assertNotEmpty($citations);
        $this->assertSame($chunk1->id, $citations[0]['chunk_id']);
        $this->assertSame('Mech room SOO', $citations[0]['document_title']);
        $this->assertGreaterThan($citations[1]['score'] ?? -INF, $citations[0]['score']);

        // Worker recorded both calls in order.
        $this->assertSame('embedQuery', $fake->calls[0]['op']);
        $this->assertSame('answer', $fake->calls[1]['op']);
        $this->assertSame('When does AHU-1 start?', $fake->calls[0]['args']['text']);
    }

    public function test_no_chunks_yields_ready_with_no_context_message(): void
    {
        // No embedded chunks in the database.
        $fake = new FakeWorkerRagClient(embedding: [1.0, 0.0]);
        $service = new QaService($fake, new VectorSearch);

        $question = Question::create([
            'question' => 'Anything?',
            'status' => QuestionStatus::Pending,
            'asked_at' => now(),
        ]);

        $result = $service->process($question);

        $this->assertSame(QuestionStatus::Ready, $result->status);
        $this->assertStringContainsString('No relevant context', (string) $result->answer);
        $this->assertSame([], $result->metadata['citations']);
        $this->assertTrue((bool) ($result->metadata['no_context'] ?? false));

        // Crucially: the worker.answer call was NOT made (we didn't waste a
        // token-paid call on no context).
        $ops = array_column($fake->calls, 'op');
        $this->assertSame(['embedQuery'], $ops);
    }

    public function test_embed_failure_persists_failed(): void
    {
        $fake = (new FakeWorkerRagClient)
            ->throwOnEmbed(new \RuntimeException('worker /qa/embed returned 503'));

        $service = new QaService($fake, new VectorSearch);

        $question = Question::create([
            'question' => 'Why?',
            'status' => QuestionStatus::Pending,
            'asked_at' => now(),
        ]);

        $result = $service->process($question);

        $this->assertSame(QuestionStatus::Failed, $result->status);
        $this->assertStringContainsString('503', (string) $result->error);
        $this->assertNull($result->answer);
    }

    public function test_answer_failure_persists_failed(): void
    {
        $doc = Document::factory()->create();
        DocumentChunk::factory()->forDocument($doc)->embedded([1.0, 0.0])->create();

        $fake = (new FakeWorkerRagClient(embedding: [1.0, 0.0]))
            ->throwOnAnswer(new \RuntimeException('LLM upstream timeout'));

        $service = new QaService($fake, new VectorSearch);

        $question = Question::create([
            'question' => 'Why?',
            'status' => QuestionStatus::Pending,
            'asked_at' => now(),
        ]);

        $result = $service->process($question);

        $this->assertSame(QuestionStatus::Failed, $result->status);
        $this->assertStringContainsString('timeout', (string) $result->error);
    }

    public function test_empty_embedding_vector_persists_failed(): void
    {
        $fake = new FakeWorkerRagClient(embedding: []);
        $service = new QaService($fake, new VectorSearch);

        $question = Question::create([
            'question' => '?',
            'status' => QuestionStatus::Pending,
            'asked_at' => now(),
        ]);

        $result = $service->process($question);

        $this->assertSame(QuestionStatus::Failed, $result->status);
        $this->assertStringContainsString('empty embedding', (string) $result->error);
    }

    public function test_site_id_filters_the_vector_search(): void
    {
        $siteA = Site::factory()->create();
        $siteB = Site::factory()->create();

        $docA = Document::factory()->forSite($siteA)->create();
        DocumentChunk::factory()->forDocument($docA)
            ->embedded([1.0, 0.0])->create(['content' => 'A chunk']);

        $docB = Document::factory()->forSite($siteB)->create();
        DocumentChunk::factory()->forDocument($docB)
            ->embedded([1.0, 0.0])->create(['content' => 'B chunk']);

        $fake = new FakeWorkerRagClient(embedding: [1.0, 0.0]);
        $service = new QaService($fake, new VectorSearch);

        $question = Question::create([
            'site_id' => $siteA->id,
            'question' => 'For site A only',
            'status' => QuestionStatus::Pending,
            'asked_at' => now(),
        ]);

        $service->process($question);

        // Worker.answer received only the A-site chunk.
        $contexts = $fake->calls[1]['args']['contexts'];
        $this->assertCount(1, $contexts);
        $this->assertSame('A chunk', $contexts[0]['content']);
    }
}
