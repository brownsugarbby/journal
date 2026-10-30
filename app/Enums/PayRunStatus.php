<?php

namespace App\Enums;

use Filament\Support\Contracts\HasColor;
use Filament\Support\Contracts\HasLabel;

/**
 * Draft runs are edited cell by cell; a paid run is locked and posted.
 */
enum PayRunStatus: string implements HasColor, HasLabel
{
    case Draft = 'draft';
    case Paid = 'paid';

    public function getLabel(): string
    {
        return match ($this) {
            self::Draft => __('Draft'),
            self::Paid => __('Paid'),
        };
    }

    public function getColor(): string
    {
        return match ($this) {
            self::Draft => 'warning',
            self::Paid => 'success',
        };
    }
}
