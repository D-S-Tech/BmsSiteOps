<?php

namespace App\Filament\Resources\TriageRules;

use App\Filament\Resources\TriageRules\Pages\CreateTriageRule;
use App\Filament\Resources\TriageRules\Pages\EditTriageRule;
use App\Filament\Resources\TriageRules\Pages\ListTriageRules;
use App\Filament\Resources\TriageRules\Schemas\TriageRuleForm;
use App\Filament\Resources\TriageRules\Tables\TriageRulesTable;
use App\Models\TriageRule;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use UnitEnum;

class TriageRuleResource extends Resource
{
    protected static ?string $model = TriageRule::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedFunnel;

    protected static string|UnitEnum|null $navigationGroup = 'Triage';

    protected static ?int $navigationSort = 1;

    protected static ?string $recordTitleAttribute = 'name';

    public static function form(Schema $schema): Schema
    {
        return TriageRuleForm::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return TriageRulesTable::configure($table);
    }

    public static function getRelations(): array
    {
        return [];
    }

    public static function getPages(): array
    {
        return [
            'index' => ListTriageRules::route('/'),
            'create' => CreateTriageRule::route('/create'),
            'edit' => EditTriageRule::route('/{record}/edit'),
        ];
    }
}
