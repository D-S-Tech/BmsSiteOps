<?php

declare(strict_types=1);

namespace Tests\Feature\Api;

use App\Enums\DocumentStatus;
use App\Models\Document;
use App\Models\DocumentChunk;
use App\Models\Site;
use App\Models\Tenant;
use App\Models\User;
use App\Support\CurrentTenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Public CRUD for knowledge-base documents.
 */
class DocumentApiTest extends TestCase
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
        $this->postJson('/api/v1/documents', [])->assertStatus(401);
    }

    public function test_store_creates_a_pending_document_and_chunks(): void
    {
        Sanctum::actingAs($this->user);

        $payload = [
            'title' => '80 Pine St SOO',
            'content' => "Mechanical room overview.\n\nAHU-1 serves the lobby. CHWS/CHWR via the building chilled water loop.\n\nVRF system: Mitsubishi TUHYE1203AN41AN x2 outdoors, twin tee in mech room.",
            'source_type' => 'manual',
        ];

        $response = $this->postJson('/api/v1/documents', $payload)->assertStatus(201);

        $response
            ->assertJsonPath('data.title', $payload['title'])
            ->assertJsonPath('data.status', 'pending')
            ->assertJsonPath('data.is_pending', true)
            ->assertJsonPath('data.source_type', 'manual');

        $this->assertSame(1, Document::count());
        $document = Document::first();
        $this->assertSame($this->user->id, $document->uploaded_by_user_id);
        $this->assertSame(DocumentStatus::Pending, $document->status);

        // Short content -> 1 chunk; tenant_id stamped automatically.
        $this->assertSame(1, $document->chunks()->count());
        $this->assertSame($this->tenant->id, $document->chunks->first()->tenant_id);
    }

    public function test_store_chunks_long_content_into_many(): void
    {
        Sanctum::actingAs($this->user);

        $longContent = str_repeat("Paragraph filler.\n\n", 200);
        $this->postJson('/api/v1/documents', [
            'title' => 'Long doc',
            'content' => $longContent,
        ])->assertStatus(201);

        $doc = Document::first();
        $this->assertGreaterThan(1, $doc->chunks()->count());

        // Positions are 0..N-1 and contiguous.
        $positions = $doc->chunks()->orderBy('position')->pluck('position')->all();
        $this->assertSame(range(0, count($positions) - 1), $positions);
    }

    public function test_store_can_bind_document_to_a_site(): void
    {
        $site = Site::factory()->create();
        Sanctum::actingAs($this->user);

        $this->postJson('/api/v1/documents', [
            'title' => 'Site spec',
            'content' => 'Site-specific content.',
            'site_id' => $site->id,
        ])->assertStatus(201)
            ->assertJsonPath('data.site_id', $site->id);

        $this->assertSame($site->id, Document::first()->site_id);
    }

    public function test_store_validates_payload(): void
    {
        Sanctum::actingAs($this->user);

        $this->postJson('/api/v1/documents', [
            'title' => '',
            // no content
            'site_id' => 999999, // doesn't exist
        ])->assertStatus(422)
            ->assertJsonValidationErrors(['title', 'content', 'site_id']);
    }

    // --- GET index -----------------------------------------------------------

    public function test_index_requires_auth(): void
    {
        $this->getJson('/api/v1/documents')->assertStatus(401);
    }

    public function test_index_lists_newest_first_and_tenant_scoped(): void
    {
        $older = Document::factory()->create(['title' => 'Older']);
        // Make sure timestamps differ even on fast machines.
        $older->forceFill(['created_at' => now()->subHour()])->save();
        Document::factory()->create(['title' => 'Newer']);

        $other = Tenant::create(['slug' => 'other', 'name' => 'Other']);
        CurrentTenant::set($other);
        Document::factory()->create(['title' => 'Foreign']);
        CurrentTenant::set($this->tenant);

        Sanctum::actingAs($this->user);
        $titles = array_column(
            $this->getJson('/api/v1/documents')->assertOk()->json('data'),
            'title'
        );
        $this->assertSame(['Newer', 'Older'], $titles);
    }

    public function test_index_includes_chunks_count(): void
    {
        $doc = Document::factory()->create();
        DocumentChunk::factory()->forDocument($doc)->count(3)->create();

        Sanctum::actingAs($this->user);
        $response = $this->getJson('/api/v1/documents')->assertOk();
        $this->assertSame(3, $response->json('data.0.chunks_count'));
    }

    // --- GET show ------------------------------------------------------------

    public function test_show_eager_loads_chunks(): void
    {
        $doc = Document::factory()->create();
        DocumentChunk::factory()->forDocument($doc)->position(0)->create(['content' => 'first']);
        DocumentChunk::factory()->forDocument($doc)->position(1)->create(['content' => 'second']);

        Sanctum::actingAs($this->user);
        $response = $this->getJson("/api/v1/documents/{$doc->id}")->assertOk();

        $this->assertCount(2, $response->json('data.chunks'));
        $this->assertSame('first', $response->json('data.chunks.0.content'));
        $this->assertSame('second', $response->json('data.chunks.1.content'));
        $this->assertFalse($response->json('data.chunks.0.embedded'));
    }

    public function test_show_omits_content_by_default_and_includes_it_on_demand(): void
    {
        $doc = Document::factory()->create(['content' => 'super secret content']);

        Sanctum::actingAs($this->user);

        $this->getJson("/api/v1/documents/{$doc->id}")
            ->assertOk()
            ->assertJsonMissingPath('data.content');

        $this->getJson("/api/v1/documents/{$doc->id}?include_content=1")
            ->assertOk()
            ->assertJsonPath('data.content', 'super secret content');
    }

    public function test_show_is_tenant_scoped(): void
    {
        $other = Tenant::create(['slug' => 'other', 'name' => 'Other']);
        CurrentTenant::set($other);
        $foreign = Document::factory()->create();
        CurrentTenant::set($this->tenant);

        Sanctum::actingAs($this->user);
        $this->getJson("/api/v1/documents/{$foreign->id}")->assertStatus(404);
    }

    // --- DELETE --------------------------------------------------------------

    public function test_destroy_cascades_chunks(): void
    {
        $doc = Document::factory()->create();
        DocumentChunk::factory()->forDocument($doc)->count(5)->create();

        Sanctum::actingAs($this->user);
        $this->deleteJson("/api/v1/documents/{$doc->id}")->assertNoContent();

        $this->assertSame(0, Document::count());
        $this->assertSame(0, DocumentChunk::count());
    }

    public function test_destroy_is_tenant_scoped(): void
    {
        $other = Tenant::create(['slug' => 'other', 'name' => 'Other']);
        CurrentTenant::set($other);
        $foreign = Document::factory()->create();
        CurrentTenant::set($this->tenant);

        Sanctum::actingAs($this->user);
        $this->deleteJson("/api/v1/documents/{$foreign->id}")->assertStatus(404);
    }
}
