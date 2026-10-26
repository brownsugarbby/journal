<?php

namespace App\Enums;

use Filament\Support\Contracts\HasColor;
use Filament\Support\Contracts\HasLabel;

/**
 * What a company account holds money in.
 */
enum AccountKind: string implements HasColor, HasLabel
{
    case Bank = 'bank';
    case Gateway = 'gateway';
    case Cash = 'cash';

    public function getLabel(): string
    {
        return match ($this) {
            self::Bank => 'Bank',
            self::Gateway => 'Payment gateway',
            self::Cash => 'Cash',
        };
    }

    public function getColor(): string
    {
        return match ($this) {
            self::Bank => 'info',
            self::Gateway => 'warning',
            self::Cash => 'gray',
        };
    }
}
