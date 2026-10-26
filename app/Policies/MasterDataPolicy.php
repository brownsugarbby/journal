<?php

namespace App\Policies;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;

/**
 * Master data is managed by superadmins and leaders. Nothing is deleted: rows are
 * deactivated so later journals keep a valid reference.
 */
class MasterDataPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->hasFullAccess();
    }

    public function view(User $user, Model $model): bool
    {
        return $user->hasFullAccess();
    }

    public function create(User $user): bool
    {
        return $user->hasFullAccess();
    }

    public function update(User $user, Model $model): bool
    {
        return $user->hasFullAccess();
    }

    public function delete(User $user, Model $model): bool
    {
        return false;
    }

    public function deleteAny(User $user): bool
    {
        return false;
    }
}
