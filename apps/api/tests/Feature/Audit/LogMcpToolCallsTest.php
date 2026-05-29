<?php

declare(strict_types=1);

namespace Tests\Feature\Audit;

use App\Models\McpAuditEntry;
use App\Models\Site;
use App\Models\Tenant;
use App\Models\User;
use App\Support\CurrentTenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class LogMcpToolCallsTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tenant = Tenant::create(['slug' => 'acme', 'name' => 'Acme']);
        $this->user = User::factory()->create([
            'current_tenant_id' => $this->tenant->id,
        ]);
        $this->user->tenants()->attach($this->tenant->id);
        CurrentTenant::set($this->tenant);
    }

    protected function tearDown(): void
    {
        CurrentTenant::forget();
        parent::tearDown();
    }

    public function test_writes_audit_entry_when_x_mcp_tool_header_present_on_authed_request(): void
    {
        Sanctum::actingAs($this->user);
        Site::factory()->create();

        $response = $this->withHeaders(['X-MCP-Tool' => 'bmssiteops_list_sites'])
            ->getJson('/api/v1/sites');

        $response->assertOk();

        $entry = McpAuditEntry::withoutGlobalScopes()->latest()->first();
        $this->assertNotNull($entry, 'middleware should write an audit entry');
        $this->assertSame('bmssiteops_list_sites', $entry->tool_name);
        $this->assertSame($this->tenant->id, $entry->tenant_id);
        $this->assertSame($this->user->id, $entry->user_id);
        $this->assertSame('GET', $entry->method);
        $this->assertSame('api/v1/sites', $entry->path);
        $this->assertSame(200, $entry->response_status);
        $this->assertGreaterThanOrEqual(0, $entry->duration_ms);
    }

    public function test_writes_payload_for_post_requests(): void
    {
        Sanctum::actingAs($this->user);

        // Trigger an audit on a POST. Even if the validation rejects the
        // body (no question provided), the middleware should still record
        // the attempt — including the inbound payload.
        $this->withHeaders(['X-MCP-Tool' => 'bmssiteops_ask'])
            ->postJson('/api/v1/qa', ['question' => 'What is AHU-1?']);

        $entry = McpAuditEntry::withoutGlobalScopes()->latest()->first();
        $this->assertNotNull($entry);
        $this->assertSame('bmssiteops_ask', $entry->tool_name);
        $this->assertSame('POST', $entry->method);
        $this->assertSame(['question' => 'What is AHU-1?'], $entry->request_payload);
    }

    public function test_skips_audit_when_no_x_mcp_tool_header(): void
    {
        Sanctum::actingAs($this->user);
        Site::factory()->create();

        $response = $this->getJson('/api/v1/sites');
        $response->assertOk();

        $this->assertSame(0, McpAuditEntry::withoutGlobalScopes()->count());
    }

    public function test_truncates_oversize_payload(): void
    {
        Sanctum::actingAs($this->user);

        // Build a payload >8KB so the truncation marker fires.
        $bigText = str_repeat('a', 9000);

        $this->withHeaders(['X-MCP-Tool' => 'bmssiteops_ask'])
            ->postJson('/api/v1/qa', ['question' => $bigText]);

        $entry = McpAuditEntry::withoutGlobalScopes()->latest()->first();
        $this->assertNotNull($entry);
        $this->assertSame(true, $entry->request_payload['_truncated']);
        $this->assertGreaterThan(8192, $entry->request_payload['_size_bytes']);
    }
}
