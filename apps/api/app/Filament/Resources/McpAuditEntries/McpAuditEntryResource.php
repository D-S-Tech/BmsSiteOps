<?php

namespace App\Filament\Resources\McpAuditEntries;

use App\Filament\Resources\McpAuditEntries\Pages\ListMcpAuditEntries;
use App\Filament\Resources\McpAuditEntries\Pages\ViewMcpAuditEntry;
use App\Filament\Resources\McpAuditEntries\Tables\McpAuditEntriesTable;
use App\Models\McpAuditEntry;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use UnitEnum;

/**
 * Filament resource for browsing the MCP tool-call audit log (Sprint 11.1).
 *
 * Read-only: the underlying mcp_audit_entries table is append-only (written
 * by the LogMcpToolCalls middleware from Sprint 9.2). The resource exposes
 * a paginated list with filters by tool / status / date range, plus a
 * dedicated view page so operators can inspect the full request_payload
 * and response timing of any individual entry.
 *
 * Retention is handled by the nightly `audit:prune-mcp` schedule (also
 * Sprint 9.2) — entries older than 90 days disappear automatically and
 * don't need manual cleanup from this UI.
 */
class McpAuditEntryResource extends Resource
{
    protected static ?string $model = McpAuditEntry::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedFingerPrint;

    protected static string|UnitEnum|null $navigationGroup = 'Operations';

    protected static ?int $navigationSort = 50;

    protected static ?string $navigationLabel = 'MCP audit log';

    protected static ?string $modelLabel = 'audit entry';

    protected static ?string $pluralModelLabel = 'audit entries';

    public static function table(Table $table): Table
    {
        return McpAuditEntriesTable::configure($table);
    }

    public static function getPages(): array
    {
        // No create / edit — audit log is append-only and written by the
        // LogMcpToolCalls middleware, never by humans.
        return [
            'index' => ListMcpAuditEntries::route('/'),
            'view' => ViewMcpAuditEntry::route('/{record}'),
        ];
    }
}
