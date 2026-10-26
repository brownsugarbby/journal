<?php

namespace App\Filament\Resources\GamePanels\Pages;

use App\Filament\Resources\GamePanels\GamePanelResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ManageRecords;

class ManageGamePanels extends ManageRecords
{
    protected static string $resource = GamePanelResource::class;

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make(),
        ];
    }
}
