<?php

namespace Database\Seeders;

use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    use WithoutModelEvents;

    /**
     * Seed the application's database.
     */
    public function run(): void
    {
        $this->call(RoleSeeder::class);
        if ($this->command?->confirm('Create/update the local administrator from LOCAL_ADMIN_* environment values?', false)) {
            $this->call(LocalAdminSeeder::class);
        }
    }
}
