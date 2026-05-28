<?php

namespace App\Filament\Resources\Devices;

use App\Filament\Resources\Devices\Pages\EditDevice;
use App\Filament\Resources\Devices\Pages\ListDevices;
use App\Filament\Resources\Devices\Schemas\DeviceForm;
use App\Filament\Resources\Devices\Tables\DevicesTable;
use App\Models\Device;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use UnitEnum;

class DeviceResource extends Resource
{
    protected static ?string $model = Device::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedCpuChip;

    protected static string|UnitEnum|null $navigationGroup = 'Registry';

    protected static ?int $navigationSort = 2;

    protected static ?string $recordTitleAttribute = 'name';

    public static function form(Schema $schema): Schema
    {
        return DeviceForm::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return DevicesTable::configure($table);
    }

    public static function getRelations(): array
    {
        return [];
    }

    public static function getPages(): array
    {
        // Devices are ingested by the worker; admin can list + edit (for
        // manual correction) but not create.
        return [
            'index' => ListDevices::route('/'),
            'edit' => EditDevice::route('/{record}/edit'),
        ];
    }
}
