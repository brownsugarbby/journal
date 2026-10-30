<?php

namespace App\Enums;

use Filament\Support\Contracts\HasColor;
use Filament\Support\Contracts\HasLabel;

/**
 * PINJAMAN movements. Counterparty balance > 0 means they owe us; < 0 means we owe them.
 */
enum LoanKind: string implements HasColor, HasLabel
{
    case Given = 'given';
    case Received = 'received';
    case RepaidToUs = 'repaid_to_us';
    case RepaidByUs = 'repaid_by_us';
    case InterestPaid = 'interest_paid';

    public function getLabel(): string
    {
        return match ($this) {
            self::Given => __('Kasih pinjaman'),
            self::Received => __('Terima pinjaman'),
            self::RepaidToUs => __('Dibayar balik'),
            self::RepaidByUs => __('Bayar utang'),
            self::InterestPaid => __('Bayar bunga'),
        };
    }

    public function getColor(): string
    {
        return match ($this) {
            self::Given => 'warning',
            self::Received => 'info',
            self::RepaidToUs => 'success',
            self::RepaidByUs => 'danger',
            self::InterestPaid => 'danger',
        };
    }

    /**
     * +1 when money comes into the company account, -1 when it goes out.
     */
    public function sign(): int
    {
        return match ($this) {
            self::Given => -1,
            self::Received => 1,
            self::RepaidToUs => 1,
            self::RepaidByUs => -1,
            self::InterestPaid => -1,
        };
    }
}
