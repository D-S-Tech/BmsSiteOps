<?php

namespace App\Filament\Resources\McpAuditEntries\Tables;

use Filament\Actions\ViewAction;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;

class McpAuditEntriesTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->defaultSort('created_at', 'desc')
            ->columns([
                TextColumn::make('created_at')
                    ->label('When')
                    ->dateTime('Y-m-d H:i:s')
                    ->sortable(),

                TextColumn::make('tool_name')
                    ->label('Tool')
                    ->badge()
                    ->color(fn (string $state): string => match (true) {
                        str_starts_with($state, 'bmssiteops_') => 'info',
                        default => 'gray',
                    })
                    ->searchable()
                    ->sortable(),

                TextColumn::make('user.name')
                    ->label('User')
                    ->searchable()
                    ->toggleable()
                    ->placeholder('—'),

                TextColumn::make('method')
                    ->label('M')
                    ->badge()
                    ->color(fn (string $state): string => match ($state) {
                        'GET' => 'gray',
                        'POST' => 'success',
                        'PUT', 'PATCH' => 'warning',
                        'DELETE' => 'danger',
                        default => 'gray',
                    }),

                TextColumn::make('path')
                    ->limit(40)
                    ->tooltip(fn ($record) => $record->path)
                    ->searchable(),

                TextColumn::make('response_status')
                    ->label('Status')
                    ->badge()
                    ->color(fn (int $state): string => match (true) {
                        $state >= 500 => 'danger',
                        $state >= 400 => 'warning',
                        $state >= 200 && $state < 300 => 'success',
                        default => 'gray',
                    })
                    ->sortable(),

                TextColumn::make('duration_ms')
                    ->label('Duration')
                    ->suffix(' ms')
                    ->color(fn (int $state): string => match (true) {
                        $state >= 5000 => 'danger',
                        $state >= 1000 => 'warning',
                        default => 'gray',
                    })
                    ->sortable()
                    ->toggleable(),

                TextColumn::make('ip_address')
                    ->label('IP')
                    ->fontFamily('mono')
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                SelectFilter::make('tool_name')
                    ->label('Tool')
                    ->options([
                        'bmssiteops_list_sites' => 'list_sites',
                        'bmssiteops_site_overview' => 'site_overview',
                        'bmssiteops_ask' => 'ask',
                        'bmssiteops_create_script' => 'create_script',
                    ]),

                SelectFilter::make('response_status')
                    ->label('Status')
                    ->options([
                        '200' => '200 OK',
                        '201' => '201 Created',
                        '400' => '400 Bad Request',
                        '401' => '401 Unauthorized',
                        '403' => '403 Forbidden',
                        '404' => '404 Not Found',
                        '422' => '422 Unprocessable',
                        '500' => '500 Server Error',
                    ]),

                Filter::make('errors_only')
                    ->label('Errors only (4xx + 5xx)')
                    ->toggle()
                    ->query(fn (Builder $q): Builder => $q->where('response_status', '>=', 400)),

                Filter::make('slow_only')
                    ->label('Slow (>1s)')
                    ->toggle()
                    ->query(fn (Builder $q): Builder => $q->where('duration_ms', '>=', 1000)),

                Filter::make('last_24h')
                    ->label('Last 24h')
                    ->toggle()
                    ->query(fn (Builder $q): Builder => $q->where('created_at', '>=', Carbon::now()->subDay())),
            ])
            ->recordActions([
                ViewAction::make(),
            ])
            ->toolbarActions([]);
    }
}
