<?php

namespace App\Filament\Resources\PayColumns\Pages;

use App\Filament\Resources\PayColumns\PayColumnResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ManageRecords;

class ManagePayColumns extends ManageRecords
{
    protected static string $resource = PayColumnResource::class;

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make(),
        ];
    }
}
