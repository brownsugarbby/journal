<?php

namespace App\Enums;

use Filament\Support\Contracts\HasColor;
use Filament\Support\Contracts\HasLabel;

/**
 * Section of the monthly P&L (Hitung Bersih) a category is reported under.
 */
enum PlGroup: string implements HasColor, HasLabel
{
    case Pendapatan = 'pendapatan';
    case BiayaTetap = 'biaya_tetap';
    case BiayaVariable = 'biaya_variable';
    case NotInPl = 'not_in_pl';

    public function getLabel(): string
    {
        return match ($this) {
            self::Pendapatan => 'Pendapatan',
            self::BiayaTetap => 'Biaya Tetap',
            self::BiayaVariable => 'Biaya Variable',
            self::NotInPl => 'Not in P&L',
        };
    }

    public function getColor(): string
    {
        return match ($this) {
            self::Pendapatan => 'success',
            self::BiayaTetap => 'warning',
            self::BiayaVariable => 'danger',
            self::NotInPl => 'gray',
        };
    }
}
