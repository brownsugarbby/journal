<?php

namespace App\Enums;

use Filament\Support\Contracts\HasColor;
use Filament\Support\Contracts\HasLabel;

/**
 * Manual categories are picked on expense entries; auto ones are filled by the system.
 */
enum CategorySource: string implements HasColor, HasLabel
{
    case Manual = 'manual';
    case Auto = 'auto';

    public function getLabel(): string
    {
        return match ($this) {
            self::Manual => 'Manual',
            self::Auto => 'Auto',
        };
    }

    public function getColor(): string
    {
        return match ($this) {
            self::Manual => 'gray',
            self::Auto => 'info',
        };
    }
}
