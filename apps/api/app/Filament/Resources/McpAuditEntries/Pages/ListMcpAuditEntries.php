<?php

namespace App\Filament\Resources\McpAuditEntries\Pages;

use App\Filament\Resources\McpAuditEntries\McpAuditEntryResource;
use Filament\Resources\Pages\ListRecords;

class ListMcpAuditEntries extends ListRecords
{
    protected static string $resource = McpAuditEntryResource::class;

    // No create action — audit log is append-only.
    protected function getHeaderActions(): array
    {
        return [];
    }
}
