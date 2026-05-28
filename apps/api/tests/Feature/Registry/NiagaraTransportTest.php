<?php

declare(strict_types=1);

namespace Tests\Feature\Registry;

use App\Enums\NiagaraTransport;
use App\Enums\SourceKind;
use App\Models\Site;
use App\Models\Source;
use App\Models\Tenant;
use App\Models\User;
use App\Support\CurrentTenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Tests for the Niagara transport field added in Sprint 2.1.
 *
 * transport is only meaningful for Niagara sources (obix | rest | fox) and is
 * null for other kinds.
 */
class NiagaraTransportTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    private Site $site;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();
        $this->tenant = Tenant::create(['slug' => 'acme', 'name' => 'Acme']);
        CurrentTenant::set($this->tenant);
        $this->site = Site::factory()->create();
        $this->user = User::factory()->inTenant($this->tenant)->create();
    }

    protected function tearDown(): void
    {
        CurrentTenant::forget();
        parent::tearDown();
    }

    // --- Model cast -----------------------------------------------------------

    public function test_transport_is_cast_to_enum(): void
    {
        $source = Source::factory()->forSite($this->site)->niagara(NiagaraTransport::Obix)->create();

        $this->assertInstanceOf(NiagaraTransport::class, $source->transport);
        $this->assertSame(NiagaraTransport::Obix, $source->transport);
        $this->assertSame(SourceKind::Niagara, $source->kind);
    }

    public function test_non_niagara_source_has_null_transport(): void
    {
        $source = Source::factory()->forSite($this->site)->kind(SourceKind::Trmm)->create();

        $this->assertNull($source->transport);
    }

    public function test_niagara_factory_defaults_to_obix(): void
    {
        $source = Source::factory()->forSite($this->site)->niagara()->create();

        $this->assertSame(NiagaraTransport::Obix, $source->transport);
    }

    // --- API validation -------------------------------------------------------

    public function test_creating_niagara_source_requires_transport(): void
    {
        Sanctum::actingAs($this->user);

        $this->postJson('/api/v1/sources', [
            'site_id' => $this->site->id,
            'kind' => SourceKind::Niagara->value,
            'name' => 'JACE-8000',
            // no transport
        ])->assertStatus(422)->assertJsonValidationErrors('transport');
    }

    public function test_can_create_niagara_source_with_transport(): void
    {
        Sanctum::actingAs($this->user);

        $response = $this->postJson('/api/v1/sources', [
            'site_id' => $this->site->id,
            'kind' => SourceKind::Niagara->value,
            'transport' => NiagaraTransport::Obix->value,
            'name' => 'JACE-8000',
            'base_url' => 'https://jace.example.com',
        ])->assertCreated();

        $this->assertSame('obix', $response->json('data.transport'));
        $this->assertDatabaseHas('sources', [
            'kind' => 'niagara',
            'transport' => 'obix',
        ]);
    }

    public function test_trmm_source_does_not_require_transport(): void
    {
        Sanctum::actingAs($this->user);

        $response = $this->postJson('/api/v1/sources', [
            'site_id' => $this->site->id,
            'kind' => SourceKind::Trmm->value,
            'name' => 'TRMM',
        ])->assertCreated();

        $this->assertNull($response->json('data.transport'));
    }

    public function test_transport_is_exposed_in_resource(): void
    {
        Sanctum::actingAs($this->user);
        $source = Source::factory()->forSite($this->site)->niagara(NiagaraTransport::Fox)->create();

        $this->getJson("/api/v1/sources/{$source->id}")
            ->assertOk()
            ->assertJsonPath('data.transport', 'fox');
    }
}
