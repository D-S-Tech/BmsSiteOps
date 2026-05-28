<?php

namespace App\Filament\Resources\Devices\Pages;

use App\Filament\Resources\Devices\DeviceResource;
use Filament\Resources\Pages\ListRecords;

class ListDevices extends ListRecords
{
    protected static string $resource = DeviceResource::class;

    // No create action — devices are ingested by the worker.
    protected function getHeaderActions(): array
    {
        return [];
    }
}
