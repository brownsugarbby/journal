<?php

namespace App\Filament\Resources\LoanCounterparties\Pages;

use App\Filament\Resources\LoanCounterparties\LoanCounterpartyResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ManageRecords;

class ManageLoanCounterparties extends ManageRecords
{
    protected static string $resource = LoanCounterpartyResource::class;

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make(),
        ];
    }
}
