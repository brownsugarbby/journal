<?php

namespace App\Enums;

use Filament\Support\Contracts\HasColor;
use Filament\Support\Contracts\HasLabel;

/**
 * Operational accounts take member DP/WD; pusat accounts hold central money.
 */
enum AccountTier: string implements HasColor, HasLabel
{
    case Operational = 'operational';
    case Pusat = 'pusat';

    public function getLabel(): string
    {
        return match ($this) {
            self::Operational => __('Operational'),
            self::Pusat => __('Pusat'),
        };
    }

    public function getColor(): string
    {
        return match ($this) {
            self::Operational => 'info',
            self::Pusat => 'success',
        };
    }
}
