<?php

namespace App\Filament\Resources\Users\Pages;

use App\Enums\Role;
use App\Filament\Resources\Users\UserResource;
use App\Models\User;
use Filament\Resources\Pages\CreateRecord;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Arr;

class CreateUser extends CreateRecord
{
    protected static string $resource = UserResource::class;

    /**
     * @param  array<string, mixed>  $data
     */
    protected function handleRecordCreation(array $data): Model
    {
        $role = Role::fromState(Arr::pull($data, 'role'));

        if ($role === Role::SuperAdmin && ! auth()->user()?->can('grantSuperAdmin', User::class)) {
            abort(403);
        }

        /** @var User $user */
        $user = parent::handleRecordCreation($data);
        $user->syncRoles([$role->value]);

        activity('user')
            ->performedOn($user)
            ->event('role_assigned')
            ->withProperties(['role' => $role->value])
            ->log("Role set to {$role->value}");

        return $user;
    }
}
