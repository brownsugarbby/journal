<?php

namespace App\Policies;

use App\Models\User;

/**
 * Superadmins and leaders manage users, but only a superadmin may touch a
 * superadmin account or hand out the superadmin role (a leader must not be
 * able to promote themselves past the profit / tutup buku limits).
 * Users are never deleted (their entries must keep pointing at them for
 * the audit trail); deactivate instead.
 */
class UserPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->hasFullAccess();
    }

    public function view(User $user, User $model): bool
    {
        return $user->hasFullAccess();
    }

    public function create(User $user): bool
    {
        return $user->hasFullAccess();
    }

    public function update(User $user, User $model): bool
    {
        return $user->hasFullAccess() && ($user->isSuperAdmin() || ! $model->isSuperAdmin());
    }

    /**
     * Whether the user may give someone the superadmin role.
     */
    public function grantSuperAdmin(User $user): bool
    {
        return $user->isSuperAdmin();
    }

    public function delete(User $user, User $model): bool
    {
        return false;
    }

    public function deleteAny(User $user): bool
    {
        return false;
    }
}
