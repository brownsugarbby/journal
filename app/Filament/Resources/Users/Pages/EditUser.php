<?php

namespace App\Filament\Resources\Users\Pages;

use App\Enums\Role;
use App\Filament\Resources\Users\UserResource;
use App\Models\User;
use Filament\Resources\Pages\EditRecord;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Arr;

class EditUser extends EditRecord
{
    protected static string $resource = UserResource::class;

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    protected function mutateFormDataBeforeFill(array $data): array
    {
        /** @var User $user */
        $user = $this->getRecord();
        $data['role'] = $user->role()?->value;

        return $data;
    }

    /**
     * @param  array<string, mixed>  $data
     */
    protected function handleRecordUpdate(Model $record, array $data): Model
    {
        /** @var User $record */
        $role = Role::fromState(Arr::pull($data, 'role'))?->value;

        if ($role === Role::SuperAdmin->value && ! auth()->user()?->can('grantSuperAdmin', User::class)) {
            abort(403);
        }

        $passwordChanged = filled($data['password'] ?? null);
        $oldRole = $record->role()?->value;

        $record = parent::handleRecordUpdate($record, $data);

        if ($role !== null && $role !== $oldRole) {
            $record->syncRoles([$role]);

            activity('user')
                ->performedOn($record)
                ->event('role_assigned')
                ->withProperties(['old' => $oldRole, 'role' => $role])
                ->log("Role changed from {$oldRole} to {$role}");
        }

        if ($passwordChanged) {
            activity('user')
                ->performedOn($record)
                ->event('password_reset')
                ->log('Password changed');
        }

        return $record;
    }
}
