<?php

declare(strict_types=1);

namespace Tests\Feature\Api;

use App\Models\Document;
use App\Models\DocumentChunk;
use App\Models\Question;
use App\Models\Tenant;
use App\Models\User;
use App\Services\RAG\FakeWorkerRagClient;
use App\Services\RAG\WorkerRagClient;
use App\Support\CurrentTenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Public Q&A endpoints.
 *
 * The controller's store() runs the synchronous pipeline; we substitute a
 * FakeWorkerRagClient in the container so no real HTTP happens.
 */
class QaApiTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    private User $user;

    private FakeWorkerRagClient $fake;

    protected function setUp(): void
    {
        parent::setUp();
        $this->tenant = Tenant::create(['slug' => 'acme', 'name' => 'Acme']);
        CurrentTenant::set($this->tenant);
        $this->user = User::factory()->inTenant($this->tenant)->create();

        $this->fake = new FakeWorkerRagClient(
            embedding: [1.0, 0.0],
            answer: 'Canned API test answer.',
        );
        $this->app->instance(WorkerRagClient::class, $this->fake);
    }

    protected function tearDown(): void
    {
        CurrentTenant::forget();
        parent::tearDown();
    }

    // --- POST --------------------------------------------------------------

    public function test_store_requires_auth(): void
    {
        $this->postJson('/api/v1/qa', ['question' => 'hi'])->assertStatus(401);
    }

    public function test_store_creates_and_processes_a_question_synchronously(): void
    {
        // Seed a relevant chunk so the pipeline takes the happy path.
        $doc = Document::factory()->create(['title' => 'AHU-1 SOO']);
        DocumentChunk::factory()->forDocument($doc)
            ->embedded([1.0, 0.0])->create(['content' => 'AHU-1 controls.']);

        Sanctum::actingAs($this->user);

        $response = $this->postJson('/api/v1/qa', [
            'question' => 'When does AHU-1 start?',
        ])->assertStatus(201);

        $response
            ->assertJsonPath('data.status', 'ready')
            ->assertJsonPath('data.question', 'When does AHU-1 start?')
            ->assertJsonPath('data.answer', 'Canned API test answer.');

        $citations = $response->json('data.citations');
        $this->assertNotEmpty($citations);
        $this->assertSame('AHU-1 SOO', $citations[0]['document_title']);

        $this->assertSame(1, Question::count());
        $stored = Question::first();
        $this->assertSame($this->user->id, $stored->asked_by_user_id);
        $this->assertNotNull($stored->answered_at);
    }

    public function test_store_returns_failed_status_when_pipeline_throws(): void
    {
        $this->fake->throwOnEmbed(new \RuntimeException('worker unreachable'));

        Sanctum::actingAs($this->user);

        $response = $this->postJson('/api/v1/qa', [
            'question' => 'Anything?',
        ])->assertStatus(201);

        $response
            ->assertJsonPath('data.status', 'failed')
            ->assertJsonStructure(['data' => ['error']]);
        $this->assertStringContainsString('unreachable', $response->json('data.error'));
    }

    public function test_store_validates_payload(): void
    {
        Sanctum::actingAs($this->user);

        $this->postJson('/api/v1/qa', ['question' => 'hi'])  // too short
            ->assertStatus(422)
            ->assertJsonValidationErrors(['question']);

        $this->postJson('/api/v1/qa', [
            'question' => 'OK length here.',
            'site_id' => 999999,
        ])->assertStatus(422)
            ->assertJsonValidationErrors(['site_id']);
    }

    // --- GET index / show --------------------------------------------------

    public function test_index_lists_newest_first_and_tenant_scoped(): void
    {
        $older = Question::factory()->create(['question' => 'older?']);
        $older->forceFill(['asked_at' => now()->subHour()])->save();
        Question::factory()->create(['question' => 'newer?']);

        $other = Tenant::create(['slug' => 'other', 'name' => 'Other']);
        CurrentTenant::set($other);
        Question::factory()->create(['question' => 'foreign?']);
        CurrentTenant::set($this->tenant);

        Sanctum::actingAs($this->user);

        $questions = $this->getJson('/api/v1/qa')->assertOk()->json('data');
        $this->assertCount(2, $questions);
        $this->assertSame('newer?', $questions[0]['question']);
        $this->assertSame('older?', $questions[1]['question']);
    }

    public function test_show_is_tenant_scoped(): void
    {
        $other = Tenant::create(['slug' => 'other', 'name' => 'Other']);
        CurrentTenant::set($other);
        $foreign = Question::factory()->create();
        CurrentTenant::set($this->tenant);

        Sanctum::actingAs($this->user);
        $this->getJson("/api/v1/qa/{$foreign->id}")->assertStatus(404);
    }

    public function test_show_returns_full_resource(): void
    {
        $q = Question::factory()->ready('Test answer.')->create();

        Sanctum::actingAs($this->user);
        $this->getJson("/api/v1/qa/{$q->id}")->assertOk()
            ->assertJsonPath('data.id', $q->id)
            ->assertJsonPath('data.answer', 'Test answer.')
            ->assertJsonPath('data.status', 'ready');
    }
}
