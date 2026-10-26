<?php

namespace App\Enums;

use Filament\Support\Contracts\HasColor;
use Filament\Support\Contracts\HasLabel;

/**
 * Who carries a payment gateway's WD fee.
 */
enum FeePaidBy: string implements HasColor, HasLabel
{
    case Company = 'company';
    case Member = 'member';

    public function getLabel(): string
    {
        return match ($this) {
            self::Company => 'Company',
            self::Member => 'Member',
        };
    }

    public function getColor(): string
    {
        return match ($this) {
            self::Company => 'danger',
            self::Member => 'gray',
        };
    }
}
