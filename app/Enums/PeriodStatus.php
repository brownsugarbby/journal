<?php

namespace App\Enums;

use Filament\Support\Contracts\HasColor;
use Filament\Support\Contracts\HasLabel;

enum PeriodStatus: string implements HasColor, HasLabel
{
    case Open = 'open';
    case Closed = 'closed';

    public function getLabel(): string
    {
        return match ($this) {
            self::Open => 'Open',
            self::Closed => 'Closed',
        };
    }

    public function getColor(): string
    {
        return match ($this) {
            self::Open => 'success',
            self::Closed => 'gray',
        };
    }
}
