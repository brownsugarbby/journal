<?php

namespace Database\Seeders;

use App\Enums\Role;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Str;

/**
 * Creates the first superadmin from config('app.superadmin') (SUPERADMIN_* env). If no password
 * is set, a random one is generated and printed once to the console.
 */
class SuperAdminSeeder extends Seeder
{
    public function run(): void
    {
        $config = config('app.superadmin');
        $username = strtolower($config['username']);

        if (User::query()->where('username', $username)->exists()) {
            $this->command?->info("Superadmin '{$username}' already exists, skipped.");

            return;
        }

        $password = $config['password'] ?: Str::password(16, symbols: false);

        $user = User::query()->create([
            'name' => $config['name'],
            'username' => $username,
            'email' => $config['email'] ?: null,
            'password' => $password,
            'is_active' => true,
        ]);

        $user->assignRole(Role::SuperAdmin->value);

        if (! $config['password']) {
            $this->command?->warn("Superadmin '{$username}' created with generated password: {$password}");
            $this->command?->warn('Log in and change it from the profile page.');
        } else {
            $this->command?->info("Superadmin '{$username}' created.");
        }
    }
}
