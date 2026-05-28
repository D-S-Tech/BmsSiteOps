<?php

namespace App\Filament\Resources\TriageDecisions;

use App\Filament\Resources\TriageDecisions\Pages\ListTriageDecisions;
use App\Filament\Resources\TriageDecisions\Tables\TriageDecisionsTable;
use App\Models\TriageDecision;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use UnitEnum;

class TriageDecisionResource extends Resource
{
    protected static ?string $model = TriageDecision::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedClipboardDocumentList;

    protected static string|UnitEnum|null $navigationGroup = 'Triage';

    protected static ?int $navigationSort = 2;

    protected static ?string $label = 'Triage decision';

    public static function table(Table $table): Table
    {
        return TriageDecisionsTable::configure($table);
    }

    public static function getRelations(): array
    {
        return [];
    }

    public static function getPages(): array
    {
        // Triage decisions are append-only audit history — list-only.
        return [
            'index' => ListTriageDecisions::route('/'),
        ];
    }

    public static function canCreate(): bool
    {
        return false;
    }
}
