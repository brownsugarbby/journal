<?php

namespace App\Enums;

use Filament\Support\Contracts\HasColor;
use Filament\Support\Contracts\HasLabel;

enum BonusKind: string implements HasColor, HasLabel
{
    case Bonus = 'bonus';
    case Cashback = 'cashback';

    public function getLabel(): string
    {
        return match ($this) {
            self::Bonus => __('Bonus'),
            self::Cashback => __('Cashback'),
        };
    }

    public function getColor(): string
    {
        return match ($this) {
            self::Bonus => 'warning',
            self::Cashback => 'info',
        };
    }
}
