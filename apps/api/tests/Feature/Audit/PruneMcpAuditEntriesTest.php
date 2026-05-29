<?php

declare(strict_types=1);

namespace Tests\Feature\Audit;

use App\Models\McpAuditEntry;
use App\Models\Tenant;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Tests\TestCase;

class PruneMcpAuditEntriesTest extends TestCase
{
    use RefreshDatabase;

    public function test_prunes_entries_older_than_default_90_days(): void
    {
        $tenant = Tenant::create(['slug' => 'acme', 'name' => 'Acme']);

        // 3 old, 2 fresh
        $this->makeEntry($tenant->id, now()->subDays(100));
        $this->makeEntry($tenant->id, now()->subDays(95));
        $this->makeEntry($tenant->id, now()->subDays(91));
        $this->makeEntry($tenant->id, now()->subDays(10));
        $this->makeEntry($tenant->id, now()->subDays(2));

        $exitCode = Artisan::call('audit:prune-mcp');

        $this->assertSame(0, $exitCode);
        $this->assertSame(2, McpAuditEntry::withoutGlobalScopes()->count());
    }

    public function test_dry_run_does_not_delete_anything(): void
    {
        $tenant = Tenant::create(['slug' => 'acme', 'name' => 'Acme']);

        $this->makeEntry($tenant->id, now()->subDays(120));
        $this->makeEntry($tenant->id, now()->subDays(110));

        Artisan::call('audit:prune-mcp', ['--dry-run' => true]);

        $this->assertSame(2, McpAuditEntry::withoutGlobalScopes()->count());
    }

    public function test_custom_retention_window(): void
    {
        $tenant = Tenant::create(['slug' => 'acme', 'name' => 'Acme']);

        $this->makeEntry($tenant->id, now()->subDays(40));   // would survive 90d, deleted by 30d
        $this->makeEntry($tenant->id, now()->subDays(20));   // would survive both

        Artisan::call('audit:prune-mcp', ['--days' => 30]);

        $this->assertSame(1, McpAuditEntry::withoutGlobalScopes()->count());
    }

    public function test_rejects_non_positive_days(): void
    {
        $exit = Artisan::call('audit:prune-mcp', ['--days' => 0]);
        $this->assertNotSame(0, $exit);
    }

    private function makeEntry(int $tenantId, Carbon $createdAt): void
    {
        // Bypass Eloquent timestamps by using ::insert() directly. Calling
        // ::create() with explicit created_at/updated_at gets overridden by
        // the timestamp mutators on save().
        McpAuditEntry::withoutGlobalScopes()->insert([
            'tenant_id' => $tenantId,
            'user_id' => null,
            'tool_name' => 'bmssiteops_list_sites',
            'method' => 'GET',
            'path' => 'api/v1/sites',
            'request_payload' => null,
            'response_status' => 200,
            'duration_ms' => 42,
            'ip_address' => '127.0.0.1',
            'created_at' => $createdAt,
            'updated_at' => $createdAt,
        ]);
    }
}
