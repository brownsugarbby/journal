<?php

namespace App\Enums;

use Filament\Support\Contracts\HasColor;
use Filament\Support\Contracts\HasLabel;

enum LoanCounterpartyType: string implements HasColor, HasLabel
{
    case Employee = 'employee';
    case Partner = 'partner';
    case External = 'external';

    public function getLabel(): string
    {
        return match ($this) {
            self::Employee => 'Employee',
            self::Partner => 'Partner',
            self::External => 'External',
        };
    }

    public function getColor(): string
    {
        return match ($this) {
            self::Employee => 'info',
            self::Partner => 'success',
            self::External => 'gray',
        };
    }
}
