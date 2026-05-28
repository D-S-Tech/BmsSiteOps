<?php

namespace App\Filament\Resources\Events\Pages;

use App\Filament\Resources\Events\EventResource;
use Filament\Resources\Pages\ListRecords;

class ListEvents extends ListRecords
{
    protected static string $resource = EventResource::class;

    // No create action — events are append-only, ingested by the worker.
    protected function getHeaderActions(): array
    {
        return [];
    }
}
