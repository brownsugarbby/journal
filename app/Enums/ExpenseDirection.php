<?php

namespace App\Enums;

use Filament\Support\Contracts\HasColor;
use Filament\Support\Contracts\HasLabel;

/**
 * KELUAR pays an expense; MASUK is money coming back (refund) and lowers the month total.
 */
enum ExpenseDirection: string implements HasColor, HasLabel
{
    case Keluar = 'keluar';
    case Masuk = 'masuk';

    public function getLabel(): string
    {
        return match ($this) {
            self::Keluar => 'Keluar',
            self::Masuk => 'Masuk',
        };
    }

    public function getColor(): string
    {
        return match ($this) {
            self::Keluar => 'danger',
            self::Masuk => 'success',
        };
    }

    /**
     * +1 when money comes into the company account, -1 when it goes out.
     */
    public function sign(): int
    {
        return match ($this) {
            self::Keluar => -1,
            self::Masuk => 1,
        };
    }
}
