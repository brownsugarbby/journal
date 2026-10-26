<?php

namespace App\Policies;

use App\Models\Journal;
use App\Models\User;

/**
 * Journals are immutable history:
 * - the creator may edit their own entry inside the edit window (App Settings);
 * - only a superadmin or leader may void, which posts a reversal;
 * - nothing in a closed period changes, and nothing is ever deleted.
 */
class JournalPolicy
{
    public function viewAny(User $user): bool
    {
        return true;
    }

    public function view(User $user, Journal $journal): bool
    {
        return $user->hasFullAccess()
            || $journal->created_by === $user->id
            || ($journal->company_account_id && $user->accessibleAccounts()->whereKey($journal->company_account_id)->exists())
            || ($journal->brand_id && $user->accessibleBrands()->whereKey($journal->brand_id)->exists());
    }

    public function create(User $user): bool
    {
        return true;
    }

    public function update(User $user, Journal $journal): bool
    {
        return $journal->created_by === $user->id
            && $journal->type->isEditable()
            && ! $journal->isVoided()
            && ! $journal->isReversal()
            && $journal->period->isOpen()
            && $journal->isWithinEditWindow();
    }

    public function void(User $user, Journal $journal): bool
    {
        return $user->hasFullAccess()
            && ! $journal->isVoided()
            && ! $journal->isReversal()
            && $journal->period->isOpen();
    }

    public function delete(User $user, Journal $journal): bool
    {
        return false;
    }

    public function deleteAny(User $user): bool
    {
        return false;
    }
}
