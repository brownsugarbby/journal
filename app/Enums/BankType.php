<?php

namespace App\Enums;

use Filament\Support\Contracts\HasColor;
use Filament\Support\Contracts\HasLabel;

enum BankType: string implements HasColor, HasLabel
{
    case Bank = 'bank';
    case EWallet = 'ewallet';

    public function getLabel(): string
    {
        return match ($this) {
            self::Bank => 'Bank',
            self::EWallet => 'E-wallet',
        };
    }

    public function getColor(): string
    {
        return match ($this) {
            self::Bank => 'info',
            self::EWallet => 'warning',
        };
    }
}
