<?php

namespace App\Filament\Resources\Sources\Schemas;

use App\Enums\NiagaraTransport;
use App\Enums\SourceKind;
use Filament\Forms\Components\KeyValue;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;

class SourceForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                // tenant_id is set automatically by the BelongsToTenant trait
                // from the panel user's current tenant — never chosen here.
                Select::make('site_id')
                    ->label('Site')
                    ->relationship('site', 'name')
                    ->searchable()
                    ->preload()
                    ->required(),
                Select::make('kind')
                    ->options(SourceKind::class)
                    ->live()
                    ->required(),
                // Transport only applies to Niagara sources.
                Select::make('transport')
                    ->options(NiagaraTransport::class)
                    ->default(NiagaraTransport::Obix->value)
                    ->visible(fn (Get $get): bool => $get('kind') === SourceKind::Niagara->value)
                    ->required(fn (Get $get): bool => $get('kind') === SourceKind::Niagara->value),
                TextInput::make('name')
                    ->required()
                    ->maxLength(200),
                TextInput::make('base_url')
                    ->label('Base URL')
                    ->url()
                    ->maxLength(500),
                KeyValue::make('credentials')
                    ->keyLabel('Key')
                    ->valueLabel('Value')
                    ->helperText('API tokens / passwords. Stored encrypted at rest; never exposed via the public API.')
                    ->columnSpanFull(),
                TextInput::make('poll_interval_seconds')
                    ->label('Poll interval (seconds)')
                    ->numeric()
                    ->minValue(10)
                    ->maxValue(86400)
                    ->default(60)
                    ->required(),
                Toggle::make('is_active')
                    ->default(true),
                KeyValue::make('metadata')
                    ->columnSpanFull(),
            ]);
    }
}
