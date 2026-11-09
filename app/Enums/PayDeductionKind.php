<?php

namespace App\Enums;

use Filament\Support\Contracts\HasLabel;

/**
 * Reasons behind POTONG on a Gaji / UM row. Kasbon or a loan is not one of
 * them: that goes in the Pinjaman column, which also lowers what is owed.
 */
enum PayDeductionKind: string implements HasLabel
{
    case Absen = 'absen';
    case Terlambat = 'terlambat';
    case Denda = 'denda';
    case Lainnya = 'lainnya';

    public function getLabel(): string
    {
        return match ($this) {
            self::Absen => __('Absent / leave'),
            self::Terlambat => __('Late'),
            self::Denda => __('Fine'),
            self::Lainnya => __('Other'),
        };
    }
}
