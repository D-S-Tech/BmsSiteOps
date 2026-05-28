<?php

namespace App\Filament\Resources\Events;

use App\Filament\Resources\Events\Pages\ListEvents;
use App\Filament\Resources\Events\Tables\EventsTable;
use App\Models\Event;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use UnitEnum;

class EventResource extends Resource
{
    protected static ?string $model = Event::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedBell;

    protected static string|UnitEnum|null $navigationGroup = 'Registry';

    protected static ?int $navigationSort = 3;

    public static function table(Table $table): Table
    {
        return EventsTable::configure($table);
    }

    public static function getRelations(): array
    {
        return [];
    }

    public static function getPages(): array
    {
        // Events are append-only and ingested by the worker — list-only.
        return [
            'index' => ListEvents::route('/'),
        ];
    }
}
