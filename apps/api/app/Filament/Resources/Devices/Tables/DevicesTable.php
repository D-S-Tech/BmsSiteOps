<?php

namespace App\Filament\Resources\Devices\Tables;

use App\Enums\DeviceStatus;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Table;

class DevicesTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('name')
                    ->searchable()
                    ->sortable(),
                TextColumn::make('site.name')
                    ->label('Site')
                    ->searchable()
                    ->sortable(),
                TextColumn::make('source.name')
                    ->label('Source')
                    ->searchable()
                    ->toggleable(),
                TextColumn::make('type')
                    ->badge()
                    ->placeholder('—')
                    ->toggleable(),
                TextColumn::make('status')
                    ->badge()
                    ->sortable(),
                IconColumn::make('is_muted')
                    ->label('Muted')
                    ->boolean()
                    ->toggleable(),
                TextColumn::make('last_seen_at')
                    ->label('Last seen')
                    ->dateTime()
                    ->sortable()
                    ->placeholder('Never'),
            ])
            ->filters([
                SelectFilter::make('status')
                    ->options(DeviceStatus::class),
                SelectFilter::make('site')
                    ->relationship('site', 'name'),
                TernaryFilter::make('is_muted')
                    ->label('Muted'),
            ])
            ->recordActions([
                EditAction::make(),
            ])
            ->toolbarActions([
                BulkActionGroup::make([
                    DeleteBulkAction::make(),
                ]),
            ]);
    }
}
