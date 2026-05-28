<?php

namespace App\Filament\Resources\TriageDecisions\Tables;

use App\Enums\TriageAction;
use App\Enums\TriageStatus;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;

class TriageDecisionsTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('occurred_at')
                    ->dateTime()
                    ->sortable(),
                TextColumn::make('rule.name')
                    ->label('Rule')
                    ->searchable()
                    ->limit(40),
                TextColumn::make('site.name')
                    ->label('Site')
                    ->toggleable(),
                TextColumn::make('action')
                    ->badge(),
                TextColumn::make('status')
                    ->badge(),
                TextColumn::make('event_id')
                    ->label('Event #')
                    ->toggleable(),
                TextColumn::make('notes')
                    ->limit(60)
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->defaultSort('occurred_at', 'desc')
            ->filters([
                SelectFilter::make('action')
                    ->options(TriageAction::class),
                SelectFilter::make('status')
                    ->options(TriageStatus::class),
                SelectFilter::make('site')
                    ->relationship('site', 'name'),
            ]);
    }
}
