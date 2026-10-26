<?php

namespace App\Enums;

use Filament\Support\Contracts\HasColor;
use Filament\Support\Contracts\HasLabel;

/**
 * Info columns are shown but not counted; additions and deductions change net pay.
 */
enum PayColumnKind: string implements HasColor, HasLabel
{
    case Info = 'info';
    case Addition = 'addition';
    case Deduction = 'deduction';

    public function getLabel(): string
    {
        return match ($this) {
            self::Info => 'Info',
            self::Addition => 'Addition',
            self::Deduction => 'Deduction',
        };
    }

    public function getColor(): string
    {
        return match ($this) {
            self::Info => 'gray',
            self::Addition => 'success',
            self::Deduction => 'danger',
        };
    }
}
