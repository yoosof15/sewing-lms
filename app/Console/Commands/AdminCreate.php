<?php

namespace App\Console\Commands;

use App\Models\User;
use App\Support\IranianMobile;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rules\Password;
use Throwable;

class AdminCreate extends Command
{
    protected $signature = 'admin:create {--first-name=} {--last-name=} {--email=} {--mobile=}';

    protected $description = 'Interactively create a production administrator without storing credentials in files';

    public function handle(): int
    {
        $firstName = trim((string) ($this->option('first-name') ?: $this->ask('First name')));
        $lastName = trim((string) ($this->option('last-name') ?: $this->ask('Last name')));
        $email = strtolower(trim((string) ($this->option('email') ?: $this->ask('Email'))));
        $rawMobile = (string) ($this->option('mobile') ?: $this->ask('Iranian mobile'));
        $password = (string) $this->secret('Password (minimum 12 characters)');
        $confirmation = (string) $this->secret('Confirm password');

        try {
            $mobile = IranianMobile::normalize($rawMobile);
        } catch (Throwable) {
            $this->components->error('The mobile number is invalid.');

            return self::INVALID;
        }

        $validator = Validator::make(compact('firstName', 'lastName', 'email', 'mobile', 'password', 'confirmation'), [
            'firstName' => ['required', 'string', 'max:100'],
            'lastName' => ['required', 'string', 'max:100'],
            'email' => ['required', 'email', 'max:255', 'unique:users,email'],
            'mobile' => ['required', 'unique:users,mobile'],
            'password' => ['required', 'same:confirmation', Password::min(12)->letters()->numbers()],
        ]);
        if ($validator->fails()) {
            foreach ($validator->errors()->all() as $error) {
                $this->components->error($error);
            }

            return self::INVALID;
        }

        DB::transaction(function () use ($firstName, $lastName, $email, $mobile, $password): void {
            $admin = User::create([
                'first_name' => $firstName,
                'last_name' => $lastName,
                'email' => $email,
                'mobile' => $mobile,
                'password' => Hash::make($password),
                'is_active' => true,
            ]);
            $admin->assignRole('ADMIN');
        });
        $this->components->info('Administrator created. No password was printed or written to disk.');

        return self::SUCCESS;
    }
}
