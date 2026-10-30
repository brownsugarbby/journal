<?php

namespace App\Enums;

use Filament\Support\Contracts\HasColor;
use Filament\Support\Contracts\HasLabel;

enum AdjustmentDirection: string implements HasColor, HasLabel
{
    case Plus = 'plus';
    case Minus = 'minus';

    public function getLabel(): string
    {
        return match ($this) {
            self::Plus => __('Tambah saldo'),
            self::Minus => __('Kurangi saldo'),
        };
    }

    public function getColor(): string
    {
        return match ($this) {
            self::Plus => 'success',
            self::Minus => 'danger',
        };
    }

    /**
     * +1 when money comes into the company account, -1 when it goes out.
     */
    public function sign(): int
    {
        return match ($this) {
            self::Plus => 1,
            self::Minus => -1,
        };
    }
}
