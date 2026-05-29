<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\McpAuditEntry;
use Illuminate\Console\Command;

/**
 * Delete mcp_audit_entries older than the retention window (Sprint 9.2).
 *
 * Wired to the scheduler for nightly automatic pruning; safe to run by
 * hand. The default 90-day window is a compromise between forensics
 * (more is better) and table size (less is better).
 *
 *     php artisan audit:prune-mcp                # default 90 days
 *     php artisan audit:prune-mcp --days=30      # tighter retention
 *     php artisan audit:prune-mcp --days=365 --dry-run   # what would go
 */
class PruneMcpAuditEntries extends Command
{
    protected $signature = 'audit:prune-mcp
                            {--days=90 : Retention window in days. Entries older than this are deleted.}
                            {--dry-run : Report how many rows would be deleted without deleting.}';

    protected $description = 'Delete mcp_audit_entries rows older than the retention window';

    public function handle(): int
    {
        $days = (int) $this->option('days');
        if ($days < 1) {
            $this->error('--days must be a positive integer');

            return self::FAILURE;
        }

        $cutoff = now()->subDays($days);

        // We deliberately bypass the tenant scope here — the prune command
        // is a system-wide maintenance task. McpAuditEntry uses the
        // BelongsToTenant trait whose global scope would otherwise filter
        // rows to the current tenant, but there's no current tenant in a
        // scheduled-command context.
        $query = McpAuditEntry::withoutGlobalScopes()
            ->where('created_at', '<', $cutoff);

        $count = $query->count();

        if ($this->option('dry-run')) {
            $this->info("[dry-run] would delete {$count} entries older than {$cutoff->toIso8601String()} ({$days} days)");

            return self::SUCCESS;
        }

        if ($count === 0) {
            $this->info("no entries older than {$days} days; nothing to do");

            return self::SUCCESS;
        }

        $deleted = $query->delete();

        $this->info("deleted {$deleted} entries older than {$cutoff->toIso8601String()} ({$days} days)");

        return self::SUCCESS;
    }
}
