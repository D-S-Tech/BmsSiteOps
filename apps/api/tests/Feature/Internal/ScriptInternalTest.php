<?php

declare(strict_types=1);

namespace Tests\Feature\Internal;

use App\Enums\ScriptLanguage;
use App\Enums\ScriptStatus;
use App\Models\Script;
use App\Models\Tenant;
use App\Support\CurrentTenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Tests for the internal worker→API AI Script endpoints (Sprint 6.1):
 *   POST /internal/scripts/claim          — atomically claim next pending
 *   POST /internal/scripts/{id}/result    — submit ready/failed result
 *
 * Both are HMAC-authenticated and cross-tenant (the worker does not pre-pick
 * a tenant; the script row carries it). The tenant is established from the
 * claimed script for any subsequent tenant-scoped writes.
 */
class ScriptInternalTest extends TestCase
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
        $this->postJson('/internal/scripts/claim')->assertStatus(401);
    }

    public function test_claim_returns_204_when_queue_is_empty(): void
    {
        [$server, $body] = $this->signPost([]);
        $this->call('POST', '/internal/scripts/claim', [], [], [], $server, $body)
            ->assertNoContent();
    }

    public function test_claim_returns_oldest_requested_and_flips_status(): void
    {
        $older = Script::factory()->create(['requested_at' => now()->subHour()]);
        Script::factory()->create(['requested_at' => now()]);
        // A non-Requested script must be ignored even if older.
        Script::factory()->status(ScriptStatus::Ready)->create(['requested_at' => now()->subDay()]);

        [$server, $body] = $this->signPost([]);
        $response = $this->call('POST', '/internal/scripts/claim', [], [], [], $server, $body)
            ->assertOk();

        $response
            ->assertJsonPath('data.id', $older->id)
            ->assertJsonPath('data.status', 'generating')
            ->assertJsonPath('data.is_pending', true);

        $fresh = $older->refresh();
        $this->assertSame(ScriptStatus::Generating, $fresh->status);
        $this->assertNotNull($fresh->claimed_at);
    }

    public function test_claim_works_across_tenants(): void
    {
        // Script lives in a different tenant — the worker should still see it.
        $other = Tenant::create(['slug' => 'other', 'name' => 'Other']);
        CurrentTenant::set($other);
        $foreign = Script::factory()->create(['requested_at' => now()->subHour()]);
        CurrentTenant::forget();

        [$server, $body] = $this->signPost([]);
        $this->call('POST', '/internal/scripts/claim', [], [], [], $server, $body)
            ->assertOk()
            ->assertJsonPath('data.id', $foreign->id);
    }

    // --- submit result --------------------------------------------------------

    public function test_submit_requires_signature(): void
    {
        $script = Script::factory()->status(ScriptStatus::Generating)->create();
        $this->postJson("/internal/scripts/{$script->id}/result", [])->assertStatus(401);
    }

    public function test_submit_ready_persists_content_and_model(): void
    {
        $script = Script::factory()
            ->language(ScriptLanguage::Python)
            ->status(ScriptStatus::Generating)
            ->create(['claimed_at' => now()->subMinute()]);

        $payload = [
            'status' => 'ready',
            'content' => "import httpx\nprint('ok')",
            'model' => 'ollama/qwen2.5-coder:32b',
            'metadata' => ['tokens' => 128, 'duration_ms' => 4200],
        ];

        [$server, $body] = $this->signPost($payload);
        $this->call('POST', "/internal/scripts/{$script->id}/result", [], [], [], $server, $body)
            ->assertOk()
            ->assertJsonPath('data.status', 'ready')
            ->assertJsonPath('data.content', $payload['content'])
            ->assertJsonPath('data.model', $payload['model'])
            ->assertJsonPath('data.metadata.tokens', 128);

        $fresh = $script->refresh();
        $this->assertSame(ScriptStatus::Ready, $fresh->status);
        $this->assertNull($fresh->error);
        $this->assertNotNull($fresh->generated_at);
    }

    public function test_submit_failed_persists_error_and_clears_content(): void
    {
        $script = Script::factory()->status(ScriptStatus::Generating)->create();

        [$server, $body] = $this->signPost([
            'status' => 'failed',
            'error' => 'Ollama returned HTTP 503',
        ]);
        $this->call('POST', "/internal/scripts/{$script->id}/result", [], [], [], $server, $body)
            ->assertOk()
            ->assertJsonPath('data.status', 'failed')
            ->assertJsonPath('data.error', 'Ollama returned HTTP 503')
            ->assertJsonPath('data.content', null);
    }

    public function test_submit_validates_payload(): void
    {
        $script = Script::factory()->status(ScriptStatus::Generating)->create();

        // Ready without content -> error
        [$server, $body] = $this->signPost(['status' => 'ready']);
        $this->call('POST', "/internal/scripts/{$script->id}/result", [], [], [], $server, $body)
            ->assertStatus(422)
            ->assertJsonValidationErrors(['content']);

        // Failed without error -> error
        [$server, $body] = $this->signPost(['status' => 'failed']);
        $this->call('POST', "/internal/scripts/{$script->id}/result", [], [], [], $server, $body)
            ->assertStatus(422)
            ->assertJsonValidationErrors(['error']);

        // Invalid status value
        [$server, $body] = $this->signPost(['status' => 'pretending']);
        $this->call('POST', "/internal/scripts/{$script->id}/result", [], [], [], $server, $body)
            ->assertStatus(422)
            ->assertJsonValidationErrors(['status']);
    }

    public function test_submit_returns_409_when_script_is_not_generating(): void
    {
        // Still Requested — worker tried to submit without claiming
        $script = Script::factory()->create();

        [$server, $body] = $this->signPost([
            'status' => 'ready',
            'content' => '...',
        ]);
        $this->call('POST', "/internal/scripts/{$script->id}/result", [], [], [], $server, $body)
            ->assertStatus(409)
            ->assertJsonPath('current_status', 'requested');
    }

    public function test_submit_returns_404_for_unknown_script(): void
    {
        [$server, $body] = $this->signPost([
            'status' => 'ready',
            'content' => '...',
        ]);
        $this->call('POST', '/internal/scripts/9999/result', [], [], [], $server, $body)
            ->assertStatus(404);
    }

    // --- signing helpers ------------------------------------------------------

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
