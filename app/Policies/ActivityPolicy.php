<?php

namespace App\Policies;

use App\Models\User;
use Spatie\Activitylog\Models\Activity;

/**
 * The audit log is read-only for everyone and visible to superadmins and leaders.
 */
class ActivityPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->hasFullAccess();
    }

    public function view(User $user, Activity $activity): bool
    {
        return $user->hasFullAccess();
    }

    public function create(User $user): bool
    {
        return false;
    }

    public function update(User $user, Activity $activity): bool
    {
        return false;
    }

    public function delete(User $user, Activity $activity): bool
    {
        return false;
    }

    public function deleteAny(User $user): bool
    {
        return false;
    }
}
