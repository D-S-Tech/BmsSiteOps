<?php

namespace App\Filament\Resources\McpAuditEntries\Pages;

use App\Filament\Resources\McpAuditEntries\McpAuditEntryResource;
use Filament\Infolists\Components\KeyValueEntry;
use Filament\Infolists\Components\TextEntry;
use Filament\Resources\Pages\ViewRecord;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;

class ViewMcpAuditEntry extends ViewRecord
{
    protected static string $resource = McpAuditEntryResource::class;

    public function infolist(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make('Call')
                    ->columns(2)
                    ->schema([
                        TextEntry::make('created_at')
                            ->label('Occurred at')
                            ->dateTime('Y-m-d H:i:s'),
                        TextEntry::make('tool_name')
                            ->badge(),
                        TextEntry::make('method')
                            ->badge(),
                        TextEntry::make('path')
                            ->fontFamily('mono'),
                        TextEntry::make('response_status')
                            ->label('HTTP status')
                            ->badge()
                            ->color(fn (int $state): string => match (true) {
                                $state >= 500 => 'danger',
                                $state >= 400 => 'warning',
                                default => 'success',
                            }),
                        TextEntry::make('duration_ms')
                            ->label('Duration')
                            ->suffix(' ms'),
                    ]),

                Section::make('Identity')
                    ->columns(2)
                    ->schema([
                        TextEntry::make('user.name')
                            ->label('User')
                            ->placeholder('—'),
                        TextEntry::make('user.email')
                            ->label('Email')
                            ->placeholder('—'),
                        TextEntry::make('tenant.name')
                            ->label('Tenant')
                            ->placeholder('—'),
                        TextEntry::make('ip_address')
                            ->label('IP address')
                            ->fontFamily('mono')
                            ->placeholder('—'),
                    ]),

                Section::make('Request payload')
                    ->collapsible()
                    ->schema([
                        KeyValueEntry::make('request_payload')
                            ->label('')
                            ->placeholder('(no body — typically a GET request)'),
                    ]),
            ]);
    }
}
