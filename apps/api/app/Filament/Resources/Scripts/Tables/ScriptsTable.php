<?php

namespace App\Filament\Resources\Scripts\Tables;

use App\Enums\ScriptLanguage;
use App\Enums\ScriptStatus;
use Filament\Actions\ViewAction;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;

class ScriptsTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('requested_at')
                    ->dateTime()
                    ->sortable(),
                TextColumn::make('title')
                    ->searchable()
                    ->limit(50),
                TextColumn::make('language')
                    ->badge(),
                TextColumn::make('status')
                    ->badge(),
                TextColumn::make('requestedBy.email')
                    ->label('Requested by')
                    ->placeholder('—')
                    ->toggleable(),
                TextColumn::make('model')
                    ->placeholder('—')
                    ->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('generated_at')
                    ->dateTime()
                    ->sortable()
                    ->placeholder('—')
                    ->toggleable(),
            ])
            ->defaultSort('requested_at', 'desc')
            ->filters([
                SelectFilter::make('status')
                    ->options(ScriptStatus::class),
                SelectFilter::make('language')
                    ->options(ScriptLanguage::class),
            ])
            ->recordActions([
                ViewAction::make(),
            ]);
    }
}
