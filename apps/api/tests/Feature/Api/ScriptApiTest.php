<?php

declare(strict_types=1);

namespace Tests\Feature\Api;

use App\Enums\ScriptLanguage;
use App\Enums\ScriptStatus;
use App\Models\Script;
use App\Models\Tenant;
use App\Models\User;
use App\Support\CurrentTenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Public API for AI script generation requests.
 */
class ScriptApiTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();
        $this->tenant = Tenant::create(['slug' => 'acme', 'name' => 'Acme']);
        CurrentTenant::set($this->tenant);
        $this->user = User::factory()->inTenant($this->tenant)->create();
    }

    protected function tearDown(): void
    {
        CurrentTenant::forget();
        parent::tearDown();
    }

    // --- POST -----------------------------------------------------------------

    public function test_store_requires_auth(): void
    {
        $this->postJson('/api/v1/scripts', [])->assertStatus(401);
    }

    public function test_store_creates_a_requested_script(): void
    {
        Sanctum::actingAs($this->user);

        $payload = [
            'title' => 'List all online TRMM agents',
            'prompt' => 'Write a Python snippet that uses the TRMM REST API to list all online agents.',
            'language' => ScriptLanguage::Python->value,
        ];

        $response = $this->postJson('/api/v1/scripts', $payload)->assertStatus(201);

        $response
            ->assertJsonPath('data.title', $payload['title'])
            ->assertJsonPath('data.prompt', $payload['prompt'])
            ->assertJsonPath('data.language', 'python')
            ->assertJsonPath('data.language_label', 'Python')
            ->assertJsonPath('data.highlight_hint', 'python')
            ->assertJsonPath('data.status', 'requested')
            ->assertJsonPath('data.is_pending', true)
            ->assertJsonPath('data.content', null);

        $this->assertSame(1, Script::count());
        $this->assertSame($this->user->id, Script::first()->requested_by_user_id);
        $this->assertNotNull(Script::first()->requested_at);
    }

    public function test_store_validates_payload(): void
    {
        Sanctum::actingAs($this->user);

        $this->postJson('/api/v1/scripts', [
            'title' => '',
            'prompt' => str_repeat('x', 6000),
            'language' => 'cobol',
        ])->assertStatus(422)
            ->assertJsonValidationErrors(['title', 'prompt', 'language']);
    }

    // --- GET index -----------------------------------------------------------

    public function test_index_requires_auth(): void
    {
        $this->getJson('/api/v1/scripts')->assertStatus(401);
    }

    public function test_index_lists_newest_first_and_is_tenant_scoped(): void
    {
        Script::factory()->create(['title' => 'Older', 'requested_at' => now()->subHour()]);
        Script::factory()->create(['title' => 'Newer', 'requested_at' => now()]);

        // Other tenant has a script we must not see.
        $other = Tenant::create(['slug' => 'other', 'name' => 'Other']);
        CurrentTenant::set($other);
        Script::factory()->create(['title' => 'Foreign']);
        CurrentTenant::set($this->tenant);

        Sanctum::actingAs($this->user);
        $titles = array_column(
            $this->getJson('/api/v1/scripts')->assertOk()->json('data'),
            'title'
        );
        $this->assertSame(['Newer', 'Older'], $titles);
    }

    // --- GET show ------------------------------------------------------------

    public function test_show_returns_full_detail_for_a_ready_script(): void
    {
        $script = Script::factory()
            ->language(ScriptLanguage::EspHomeYaml)
            ->ready("esphome:\n  name: my-node\n")
            ->create(['title' => 'ESP32 device config']);

        Sanctum::actingAs($this->user);
        $this->getJson("/api/v1/scripts/{$script->id}")
            ->assertOk()
            ->assertJsonPath('data.status', 'ready')
            ->assertJsonPath('data.is_pending', false)
            ->assertJsonPath('data.highlight_hint', 'yaml')
            ->assertJsonPath('data.content', "esphome:\n  name: my-node\n")
            ->assertJsonPath('data.model', 'ollama/qwen2.5-coder:32b');
    }

    public function test_show_is_tenant_scoped(): void
    {
        $other = Tenant::create(['slug' => 'other', 'name' => 'Other']);
        CurrentTenant::set($other);
        $foreign = Script::factory()->create();
        CurrentTenant::set($this->tenant);

        Sanctum::actingAs($this->user);
        $this->getJson("/api/v1/scripts/{$foreign->id}")->assertStatus(404);
    }

    public function test_is_pending_flips_when_status_terminal(): void
    {
        $pending = Script::factory()->create();              // Requested
        $generating = Script::factory()->status(ScriptStatus::Generating)->create();
        $ready = Script::factory()->ready()->create();
        $failed = Script::factory()->failed()->create();

        Sanctum::actingAs($this->user);
        $this->assertTrue($this->getJson("/api/v1/scripts/{$pending->id}")->json('data.is_pending'));
        $this->assertTrue($this->getJson("/api/v1/scripts/{$generating->id}")->json('data.is_pending'));
        $this->assertFalse($this->getJson("/api/v1/scripts/{$ready->id}")->json('data.is_pending'));
        $this->assertFalse($this->getJson("/api/v1/scripts/{$failed->id}")->json('data.is_pending'));
    }
}
