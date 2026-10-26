<?php

namespace App\Filament\Resources\BonusTypes\Pages;

use App\Filament\Resources\BonusTypes\BonusTypeResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ManageRecords;

class ManageBonusTypes extends ManageRecords
{
    protected static string $resource = BonusTypeResource::class;

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make(),
        ];
    }
}
