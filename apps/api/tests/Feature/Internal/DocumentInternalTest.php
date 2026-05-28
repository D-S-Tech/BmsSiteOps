<?php

declare(strict_types=1);

namespace Tests\Feature\Internal;

use App\Enums\DocumentStatus;
use App\Models\Document;
use App\Models\DocumentChunk;
use App\Models\Tenant;
use App\Support\CurrentTenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Tests for the internal worker→API RAG embedding endpoints (Sprint 7.2):
 *   POST /internal/documents/claim                — atomic claim next pending
 *   POST /internal/documents/{id}/embeddings      — submit chunk embeddings
 */
class DocumentInternalTest extends TestCase
{
    use RefreshDatabase;

    private string $key = 'test-worker-secret-key';

    private Tenant $tenant;

    protected function setUp(): void
    {
        parent::setUp();
        config(['services.worker.internal_key' => $this->key]);

        $this->tenant = Tenant::create(['slug' => 'acme', 'name' => 'Acme']);
        CurrentTenant::set($this->tenant);
    }

    protected function tearDown(): void
    {
        CurrentTenant::forget();
        parent::tearDown();
    }

    // --- claim ----------------------------------------------------------------

    public function test_claim_requires_signature(): void
    {
        $this->postJson('/internal/documents/claim')->assertStatus(401);
    }

    public function test_claim_returns_204_when_queue_is_empty(): void
    {
        [$server, $body] = $this->signPost([]);
        $this->call('POST', '/internal/documents/claim', [], [], [], $server, $body)
            ->assertNoContent();
    }

    public function test_claim_returns_oldest_pending_with_embedded_chunks(): void
    {
        $older = Document::factory()->create();
        DocumentChunk::factory()->forDocument($older)->position(0)->create(['content' => 'first']);
        DocumentChunk::factory()->forDocument($older)->position(1)->create(['content' => 'second']);
        // Bend created_at so the deterministic ordering applies even on fast hardware.
        $older->forceFill(['created_at' => now()->subHour()])->save();

        Document::factory()->create();
        Document::factory()->status(DocumentStatus::Ready)->create();  // not pending — ignored

        [$server, $body] = $this->signPost([]);
        $response = $this->call('POST', '/internal/documents/claim', [], [], [], $server, $body)
            ->assertOk();

        $response
            ->assertJsonPath('data.id', $older->id)
            ->assertJsonPath('data.status', 'embedding')
            ->assertJsonCount(2, 'data.chunks');

        $this->assertSame(DocumentStatus::Embedding, $older->refresh()->status);
    }

    public function test_claim_works_across_tenants(): void
    {
        $other = Tenant::create(['slug' => 'other', 'name' => 'Other']);
        CurrentTenant::set($other);
        $foreign = Document::factory()->create();
        DocumentChunk::factory()->forDocument($foreign)->create();
        $foreign->forceFill(['created_at' => now()->subHour()])->save();
        CurrentTenant::forget();

        [$server, $body] = $this->signPost([]);
        $this->call('POST', '/internal/documents/claim', [], [], [], $server, $body)
            ->assertOk()
            ->assertJsonPath('data.id', $foreign->id);
    }

    // --- submit embeddings ----------------------------------------------------

    public function test_submit_requires_signature(): void
    {
        $doc = Document::factory()->status(DocumentStatus::Embedding)->create();
        $this->postJson("/internal/documents/{$doc->id}/embeddings", [])->assertStatus(401);
    }

    public function test_submit_ready_applies_embeddings_and_flips_status(): void
    {
        $doc = Document::factory()->status(DocumentStatus::Embedding)->create();
        $chunk1 = DocumentChunk::factory()->forDocument($doc)->position(0)->create();
        $chunk2 = DocumentChunk::factory()->forDocument($doc)->position(1)->create();

        $payload = [
            'status' => 'ready',
            'chunks' => [
                [
                    'id' => $chunk1->id,
                    'embedding' => [0.1, 0.2, 0.3],
                    'embedding_model' => 'ollama/nomic-embed-text',
                    'token_count' => 12,
                ],
                [
                    'id' => $chunk2->id,
                    'embedding' => [0.4, 0.5, 0.6],
                    'embedding_model' => 'ollama/nomic-embed-text',
                    'token_count' => 8,
                ],
            ],
        ];

        [$server, $body] = $this->signPost($payload);
        $this->call('POST', "/internal/documents/{$doc->id}/embeddings", [], [], [], $server, $body)
            ->assertOk()
            ->assertJsonPath('data.status', 'ready');

        $fresh = $doc->refresh();
        $this->assertSame(DocumentStatus::Ready, $fresh->status);
        $this->assertNotNull($fresh->embedded_at);

        $c1 = $chunk1->refresh();
        $this->assertSame([0.1, 0.2, 0.3], $c1->embedding);
        $this->assertSame('ollama/nomic-embed-text', $c1->embedding_model);
        $this->assertSame(12, $c1->token_count);
        $this->assertNotNull($c1->embedded_at);
    }

    public function test_submit_failed_persists_error_and_does_not_touch_chunks(): void
    {
        $doc = Document::factory()->status(DocumentStatus::Embedding)->create();
        $chunk = DocumentChunk::factory()->forDocument($doc)->create();

        [$server, $body] = $this->signPost([
            'status' => 'failed',
            'error' => 'Ollama embeddings endpoint returned HTTP 503',
        ]);

        $this->call('POST', "/internal/documents/{$doc->id}/embeddings", [], [], [], $server, $body)
            ->assertOk()
            ->assertJsonPath('data.status', 'failed');

        $fresh = $doc->refresh();
        $this->assertSame(DocumentStatus::Failed, $fresh->status);
        $this->assertStringContainsString('503', $fresh->error);
        $this->assertNull($chunk->refresh()->embedding);
    }

    public function test_submit_validates_payload(): void
    {
        $doc = Document::factory()->status(DocumentStatus::Embedding)->create();

        // Ready without chunks
        [$server, $body] = $this->signPost(['status' => 'ready']);
        $this->call('POST', "/internal/documents/{$doc->id}/embeddings", [], [], [], $server, $body)
            ->assertStatus(422)
            ->assertJsonValidationErrors(['chunks']);

        // Failed without error
        [$server, $body] = $this->signPost(['status' => 'failed']);
        $this->call('POST', "/internal/documents/{$doc->id}/embeddings", [], [], [], $server, $body)
            ->assertStatus(422)
            ->assertJsonValidationErrors(['error']);

        // Invalid status
        [$server, $body] = $this->signPost(['status' => 'reticulating']);
        $this->call('POST', "/internal/documents/{$doc->id}/embeddings", [], [], [], $server, $body)
            ->assertStatus(422)
            ->assertJsonValidationErrors(['status']);
    }

    public function test_submit_rejects_chunk_not_belonging_to_document(): void
    {
        $doc = Document::factory()->status(DocumentStatus::Embedding)->create();
        $foreignChunk = DocumentChunk::factory()->create();  // belongs to a different doc

        [$server, $body] = $this->signPost([
            'status' => 'ready',
            'chunks' => [
                [
                    'id' => $foreignChunk->id,
                    'embedding' => [0.1, 0.2],
                    'embedding_model' => 'm',
                ],
            ],
        ]);

        $this->call('POST', "/internal/documents/{$doc->id}/embeddings", [], [], [], $server, $body)
            ->assertStatus(422);
    }

    public function test_submit_returns_409_when_document_is_not_embedding(): void
    {
        $doc = Document::factory()->create();  // Pending

        [$server, $body] = $this->signPost([
            'status' => 'ready',
            'chunks' => [['id' => 1, 'embedding' => [0.1], 'embedding_model' => 'm']],
        ]);
        $this->call('POST', "/internal/documents/{$doc->id}/embeddings", [], [], [], $server, $body)
            ->assertStatus(409)
            ->assertJsonPath('current_status', 'pending');
    }

    // --- signing helper -------------------------------------------------------

    /**
     * @param  array<string, mixed>  $payload
     * @return array{0: array<string, string>, 1: string}
     */
    private function signPost(array $payload): array
    {
        $body = json_encode($payload, JSON_THROW_ON_ERROR);
        $timestamp = (string) time();
        $signature = hash_hmac('sha256', $timestamp.'.'.$body, $this->key);

        return [
            [
                'HTTP_X_WORKER_TIMESTAMP' => $timestamp,
                'HTTP_X_WORKER_SIGNATURE' => $signature,
                'CONTENT_TYPE' => 'application/json',
            ],
            $body,
        ];
    }
}
