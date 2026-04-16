<?php

namespace Database\Seeders;

use App\Enums\ApplicationRole;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;
use Spatie\Permission\Models\Role;

class RoleAndUserSeeder extends Seeder
{
    public function run(): void
    {
        foreach (ApplicationRole::cases() as $role) {
            Role::firstOrCreate(
                ['name' => $role->value, 'guard_name' => 'web'],
            );
        }

        $superAdmin = User::query()->firstOrCreate(
            ['email' => 'super@gmail.com'],
            [
                'name' => 'Super Admin',
                'password' => Hash::make('admin@123'),
                'email_verified_at' => now(),
            ],
        );
        $superAdmin->syncRoles([ApplicationRole::SuperAdmin->value]);

        $admin = User::query()->firstOrCreate(
            ['email' => 'admin@gmail.com'],
            [
                'name' => 'Admin User',
                'password' => Hash::make('admin@123'),
                'email_verified_at' => now(),
            ],
        );
        $admin->syncRoles([ApplicationRole::Admin->value]);

        $regular = User::query()->firstOrCreate(
            ['email' => 'user@gmail.com'],
            [
                'name' => 'Regular User',
                'password' => Hash::make('user@123'),
                'email_verified_at' => now(),
            ],
        );
        $regular->syncRoles([ApplicationRole::User->value]);
    }
}
