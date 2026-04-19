<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    /**
     * Seed the application's database.
     */
    public function run(): void
    {
        $this->call(RoleAndUserSeeder::class);
        // BankSeeder gọi API banklookup — chạy riêng khi có mạng: `php artisan db:seed --class=BankSeeder`
    }
}
