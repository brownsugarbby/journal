<?php

namespace App\Enums;

use Filament\Support\Contracts\HasColor;
use Filament\Support\Contracts\HasLabel;

/**
 * - Superadmin: everything.
 * - Leader: everything except closing the books (tutup buku) and the
 *   profit calculation (Hitung Bersih / P&L); cannot touch superadmins.
 * - Admin: staff journal on assigned brands/accounts (DP/WD, bonus,
 *   cashback), plus panel report / expenses when granted.
 * - Staff CS: DP and WD on assigned accounts only.
 */
enum Role: string implements HasColor, HasLabel
{
    case SuperAdmin = 'superadmin';
    case Leader = 'leader';
    case Admin = 'admin';
    case StaffCs = 'staff_cs';

    /**
     * Filament enum selects hand back either the enum or its raw value.
     */
    public static function fromState(self|string|null $state): ?self
    {
        return $state instanceof self ? $state : self::tryFrom((string) $state);
    }

    public function getLabel(): string
    {
        return match ($this) {
            self::SuperAdmin => 'Superadmin',
            self::Leader => 'Leader',
            self::Admin => 'Admin',
            self::StaffCs => 'Staff CS',
        };
    }

    public function getColor(): string
    {
        return match ($this) {
            self::SuperAdmin => 'danger',
            self::Leader => 'warning',
            self::Admin => 'gray',
            self::StaffCs => 'info',
        };
    }

    /**
     * Roles limited to assigned brands and accounts.
     */
    public function isScoped(): bool
    {
        return ! in_array($this, [self::SuperAdmin, self::Leader], true);
    }
}
