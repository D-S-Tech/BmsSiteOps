<?php

namespace App\Filament\Resources\Events\Tables;

use App\Enums\EventSeverity;
use App\Enums\SourceKind;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;

class EventsTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->defaultSort('occurred_at', 'desc')
            ->columns([
                TextColumn::make('occurred_at')
                    ->label('Occurred')
                    ->dateTime()
                    ->sortable(),
                TextColumn::make('site.name')
                    ->label('Site')
                    ->searchable()
                    ->toggleable(),
                TextColumn::make('device.name')
                    ->label('Device')
                    ->searchable()
                    ->sortable(),
                TextColumn::make('kind')
                    ->badge()
                    ->toggleable(),
                TextColumn::make('metric')
                    ->searchable()
                    ->sortable(),
                TextColumn::make('value')
                    ->limit(60)
                    ->placeholder('—'),
                TextColumn::make('severity')
                    ->badge()
                    ->placeholder('—'),
            ])
            ->filters([
                SelectFilter::make('severity')
                    ->options(EventSeverity::class),
                SelectFilter::make('kind')
                    ->options(SourceKind::class),
                SelectFilter::make('site')
                    ->relationship('site', 'name'),
            ])
            // Events are append-only: no edit, no bulk delete from the admin.
            ->recordActions([])
            ->toolbarActions([]);
    }
}
