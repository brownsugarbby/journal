<?php

namespace App\Filament\Resources\ExpenseStatuses\Pages;

use App\Filament\Resources\ExpenseStatuses\ExpenseStatusResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ManageRecords;

class ManageExpenseStatuses extends ManageRecords
{
    protected static string $resource = ExpenseStatusResource::class;

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make(),
        ];
    }
}
