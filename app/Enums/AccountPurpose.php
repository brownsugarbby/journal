<?php

namespace App\Enums;

use Filament\Support\Contracts\HasColor;
use Filament\Support\Contracts\HasLabel;

enum AccountPurpose: string implements HasColor, HasLabel
{
    case Deposit = 'dp';
    case Withdraw = 'wd';
    case DepositWithdraw = 'dp_wd';
    case Central = 'central';
    case Cash = 'cash';

    public function getLabel(): string
    {
        return match ($this) {
            self::Deposit => __('DP'),
            self::Withdraw => __('WD'),
            self::DepositWithdraw => __('DP + WD'),
            self::Central => __('Central'),
            self::Cash => __('Cash'),
        };
    }

    public function getColor(): string
    {
        return match ($this) {
            self::Deposit => 'success',
            self::Withdraw => 'danger',
            self::DepositWithdraw => 'info',
            self::Central => 'gray',
            self::Cash => 'gray',
        };
    }
}
