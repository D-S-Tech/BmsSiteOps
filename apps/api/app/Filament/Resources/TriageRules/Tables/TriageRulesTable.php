<?php

namespace App\Filament\Resources\TriageRules\Tables;

use App\Enums\TriageAction;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteAction;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Table;

class TriageRulesTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('priority')
                    ->sortable()
                    ->numeric(),
                TextColumn::make('name')
                    ->searchable()
                    ->limit(40),
                TextColumn::make('severity_match')
                    ->label('Severity')
                    ->placeholder('any')
                    ->badge(),
                TextColumn::make('kind_match')
                    ->label('Kind')
                    ->placeholder('any')
                    ->badge(),
                TextColumn::make('metric_pattern')
                    ->label('Metric')
                    ->placeholder('any')
                    ->limit(30),
                TextColumn::make('action')
                    ->badge(),
                IconColumn::make('enabled')
                    ->boolean(),
                TextColumn::make('updated_at')
                    ->dateTime()
                    ->sortable()
                    ->toggleable(),
            ])
            ->defaultSort('priority', 'asc')
            ->filters([
                TernaryFilter::make('enabled'),
                SelectFilter::make('action')
                    ->options(TriageAction::class),
            ])
            ->recordActions([
                EditAction::make(),
                DeleteAction::make(),
            ])
            ->toolbarActions([
                BulkActionGroup::make([
                    DeleteBulkAction::make(),
                ]),
            ]);
    }
}
