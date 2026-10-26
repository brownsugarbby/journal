<?php

namespace App\Enums;

use Filament\Support\Contracts\HasColor;
use Filament\Support\Contracts\HasLabel;

/**
 * Owner money: capital put in, or prive taken out.
 */
enum PartnerKind: string implements HasColor, HasLabel
{
    case Capital = 'capital';
    case Prive = 'prive';

    public function getLabel(): string
    {
        return match ($this) {
            self::Capital => 'Modal masuk',
            self::Prive => 'Prive (ambil)',
        };
    }

    public function getColor(): string
    {
        return match ($this) {
            self::Capital => 'success',
            self::Prive => 'danger',
        };
    }

    /**
     * +1 when money comes into the company account, -1 when it goes out.
     */
    public function sign(): int
    {
        return match ($this) {
            self::Capital => 1,
            self::Prive => -1,
        };
    }
}
