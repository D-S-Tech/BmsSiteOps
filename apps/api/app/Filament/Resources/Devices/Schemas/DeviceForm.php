<?php

namespace App\Filament\Resources\Devices\Schemas;

use App\Enums\DeviceStatus;
use Filament\Forms\Components\DateTimePicker;
use Filament\Forms\Components\KeyValue;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;

class DeviceForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                // Devices are normally created by the worker via ingestion;
                // this form is mostly for inspection and manual correction.
                Select::make('source_id')
                    ->label('Source')
                    ->relationship('source', 'name')
                    ->searchable()
                    ->preload()
                    ->required(),
                Select::make('site_id')
                    ->label('Site')
                    ->relationship('site', 'name')
                    ->searchable()
                    ->preload()
                    ->required(),
                TextInput::make('external_id')
                    ->label('External ID')
                    ->required()
                    ->maxLength(255),
                TextInput::make('name')
                    ->required()
                    ->maxLength(255),
                TextInput::make('type')
                    ->maxLength(64),
                Select::make('status')
                    ->options(DeviceStatus::class)
                    ->default('unknown')
                    ->required(),
                DateTimePicker::make('last_seen_at')
                    ->label('Last seen'),
                Toggle::make('is_muted')
                    ->label('Muted')
                    ->live(),
                DateTimePicker::make('muted_until')
                    ->label('Muted until (optional)')
                    ->helperText('Leave empty to mute indefinitely.')
                    ->visible(fn (Get $get): bool => (bool) $get('is_muted')),
                KeyValue::make('metadata')
                    ->columnSpanFull(),
            ]);
    }
}
