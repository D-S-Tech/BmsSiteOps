<?php

namespace App\Filament\Resources\TriageRules\Pages;

use App\Filament\Resources\TriageRules\TriageRuleResource;
use Filament\Actions\DeleteAction;
use Filament\Resources\Pages\EditRecord;

class EditTriageRule extends EditRecord
{
    protected static string $resource = TriageRuleResource::class;

    protected function getHeaderActions(): array
    {
        return [
            DeleteAction::make(),
        ];
    }
}
