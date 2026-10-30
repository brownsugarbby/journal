<?php

namespace App\Enums;

use Filament\Support\Contracts\HasColor;
use Filament\Support\Contracts\HasLabel;

enum FeeType: string implements HasColor, HasLabel
{
    case Flat = 'flat';
    case Percent = 'percent';

    public function getLabel(): string
    {
        return match ($this) {
            self::Flat => __('Flat (Rp)'),
            self::Percent => __('Percent (%)'),
        };
    }

    public function getColor(): string
    {
        return match ($this) {
            self::Flat => 'gray',
            self::Percent => 'gray',
        };
    }
}
