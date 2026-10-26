<?php

namespace App\Policies;

use App\Models\PayRun;
use App\Models\User;

/**
 * Payroll is for superadmins and leaders. A draft sheet created by mistake may be
 * deleted; a paid one never (unpay it first, which voids its journals).
 */
class PayRunPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->hasFullAccess();
    }

    public function view(User $user, PayRun $run): bool
    {
        return $user->hasFullAccess();
    }

    public function create(User $user): bool
    {
        return $user->hasFullAccess();
    }

    public function update(User $user, PayRun $run): bool
    {
        return $user->hasFullAccess() && ! $run->isPaid();
    }

    public function delete(User $user, PayRun $run): bool
    {
        return $user->hasFullAccess() && ! $run->isPaid() && ! $run->journals()->exists();
    }

    public function deleteAny(User $user): bool
    {
        return false;
    }
}
