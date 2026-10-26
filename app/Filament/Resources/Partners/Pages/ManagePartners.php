<?php

namespace App\Filament\Resources\Partners\Pages;

use App\Filament\Resources\Partners\PartnerResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ManageRecords;

class ManagePartners extends ManageRecords
{
    protected static string $resource = PartnerResource::class;

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make(),
        ];
    }
}
