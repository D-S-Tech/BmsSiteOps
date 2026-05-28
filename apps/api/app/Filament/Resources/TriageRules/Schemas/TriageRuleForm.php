<?php

namespace App\Filament\Resources\TriageRules\Schemas;

use App\Enums\EventSeverity;
use App\Enums\SourceKind;
use App\Enums\TriageAction;
use Filament\Forms\Components\KeyValue;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Schema;

class TriageRuleForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                // tenant_id is set automatically by the BelongsToTenant trait
                // from the panel user's current tenant — never chosen here.

                TextInput::make('name')
                    ->required()
                    ->maxLength(200),
                Textarea::make('description')
                    ->maxLength(2000)
                    ->columnSpanFull(),

                // --- Match conditions (any null = wildcard) ----------------
                Select::make('severity_match')
                    ->label('Severity')
                    ->options(EventSeverity::class)
                    ->placeholder('Any')
                    ->nullable(),
                Select::make('kind_match')
                    ->label('Source kind')
                    ->options(SourceKind::class)
                    ->placeholder('Any')
                    ->nullable(),
                TextInput::make('metric_pattern')
                    ->label('Metric pattern')
                    ->maxLength(200)
                    ->helperText('Glob, case-insensitive. e.g. disk* or *temp*'),
                TextInput::make('value_contains')
                    ->label('Value contains')
                    ->maxLength(200)
                    ->helperText('Case-insensitive substring on the event value.'),

                // --- Action ------------------------------------------------
                Select::make('action')
                    ->options(TriageAction::class)
                    ->required(),
                KeyValue::make('action_params')
                    ->keyLabel('Key')
                    ->valueLabel('Value')
                    ->helperText('Optional per-action knobs. For mute_device: duration_minutes (integer) for a timed mute; omit for indefinite.')
                    ->columnSpanFull(),

                TextInput::make('priority')
                    ->numeric()
                    ->minValue(1)
                    ->maxValue(10000)
                    ->default(100)
                    ->required()
                    ->helperText('Lower number = higher priority (wins on a tie).'),
                Toggle::make('enabled')
                    ->default(true),
            ]);
    }
}
