<?php

namespace App\Filament\Resources\TriageRules\Pages;

use App\Filament\Resources\TriageRules\TriageRuleResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListTriageRules extends ListRecords
{
    protected static string $resource = TriageRuleResource::class;

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make(),
        ];
    }
}
