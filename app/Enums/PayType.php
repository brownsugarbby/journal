<?php

namespace App\Enums;

use Filament\Support\Contracts\HasColor;
use Filament\Support\Contracts\HasLabel;

enum PayType: string implements HasColor, HasLabel
{
    case Gaji = 'gaji';
    case Um = 'um';

    public function getLabel(): string
    {
        return match ($this) {
            self::Gaji => __('Gaji'),
            self::Um => __('UM'),
        };
    }

    public function getColor(): string
    {
        return match ($this) {
            self::Gaji => 'info',
            self::Um => 'warning',
        };
    }
}
