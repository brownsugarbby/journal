<?php

namespace App\Filament\Resources\Banks\Pages;

use App\Filament\Resources\Banks\BankResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ManageRecords;

class ManageBanks extends ManageRecords
{
    protected static string $resource = BankResource::class;

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make(),
        ];
    }
}
