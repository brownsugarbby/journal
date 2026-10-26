<?php

namespace App\Enums;

use Filament\Support\Contracts\HasColor;
use Filament\Support\Contracts\HasLabel;

enum JournalType: string implements HasColor, HasLabel
{
    case Deposit = 'deposit';
    case Withdraw = 'withdraw';
    case Bonus = 'bonus';
    case Cashback = 'cashback';
    case Transfer = 'transfer';
    case CurrencyPurchase = 'currency_purchase';
    case Expense = 'expense';
    case Loan = 'loan';
    case Partner = 'partner';
    case Adjustment = 'adjustment';
    case Payroll = 'payroll';

    public function getLabel(): string
    {
        return match ($this) {
            self::Deposit => 'DP',
            self::Withdraw => 'WD',
            self::Bonus => 'Bonus',
            self::Cashback => 'Cashback',
            self::Transfer => 'Transfer',
            self::CurrencyPurchase => 'Beli valas',
            self::Expense => 'Pengeluaran',
            self::Loan => 'Pinjaman',
            self::Partner => 'Partner',
            self::Adjustment => 'Penyesuaian',
            self::Payroll => 'Gaji / UM',
        };
    }

    public function getColor(): string
    {
        return match ($this) {
            self::Deposit => 'success',
            self::Withdraw => 'danger',
            self::Bonus => 'warning',
            self::Cashback, self::Transfer => 'info',
            self::CurrencyPurchase => 'primary',
            self::Expense => 'danger',
            self::Loan, self::Partner, self::Adjustment => 'gray',
            self::Payroll => 'warning',
        };
    }

    public function prefix(): string
    {
        return match ($this) {
            self::Deposit => 'DP',
            self::Withdraw => 'WD',
            self::Bonus => 'BN',
            self::Cashback => 'CB',
            self::Transfer => 'TF',
            self::CurrencyPurchase => 'FX',
            self::Expense => 'PG',
            self::Loan => 'PJ',
            self::Partner => 'PT',
            self::Adjustment => 'AJ',
            self::Payroll => 'GJ',
        };
    }

    /**
     * Bonus and cashback are given as coin in the game panel: no money moves,
     * so their journal only carries the P&L line and does not need to balance.
     */
    public function movesMoney(): bool
    {
        return ! in_array($this, [self::Bonus, self::Cashback], true);
    }

    /**
     * Types staff can correct inside the edit window. The central-money
     * types are fixed with Void and re-entry.
     */
    public function isEditable(): bool
    {
        return in_array($this, [self::Deposit, self::Withdraw, self::Bonus, self::Cashback], true);
    }
}
