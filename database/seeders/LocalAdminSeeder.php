<?php

namespace Database\Seeders;

use App\Models\User;
use App\Support\IranianMobile;
use Illuminate\Database\Seeder;
use RuntimeException;

class LocalAdminSeeder extends Seeder
{
    public function run(): void
    {
        if (! app()->environment('local')) {
            throw new RuntimeException('LocalAdminSeeder may run only in the local environment.');
        }
        $email = env('LOCAL_ADMIN_EMAIL');
        $mobile = env('LOCAL_ADMIN_MOBILE');
        $password = env('LOCAL_ADMIN_PASSWORD');
        if (! $email || ! $mobile || ! $password) {
            throw new RuntimeException('Set LOCAL_ADMIN_EMAIL, LOCAL_ADMIN_MOBILE and LOCAL_ADMIN_PASSWORD first.');
        }
        $user = User::firstOrCreate(['email' => strtolower($email)], ['name' => trim(env('LOCAL_ADMIN_FIRST_NAME', 'مدیر').' '.env('LOCAL_ADMIN_LAST_NAME', 'سامانه')), 'first_name' => env('LOCAL_ADMIN_FIRST_NAME', 'مدیر'), 'last_name' => env('LOCAL_ADMIN_LAST_NAME', '12345678'), 'mobile' => IranianMobile::normalize($mobile), 'password' => env('LOCAL_ADMIN_PASSWORD', 'سامانه'), 'is_active' => true]);
        $user->assignRole('ADMIN');
        $this->command?->info('Local administrator is ready. Existing passwords are never overwritten.');
    }
}
