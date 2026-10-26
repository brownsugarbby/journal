<?php

namespace App\Filament\Resources\CompanyAccounts\Pages;

use App\Filament\Resources\CompanyAccounts\CompanyAccountResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ManageRecords;

class ManageCompanyAccounts extends ManageRecords
{
    protected static string $resource = CompanyAccountResource::class;

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make(),
        ];
    }
}
