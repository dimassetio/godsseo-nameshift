<?php

namespace App\Console\Commands;

use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\Rules\Password;

class CreateAdmin extends Command
{
    protected $signature = 'app:create-admin {email?}';

    protected $description = 'Interactively create or update the application administrator';

    public function handle(): int
    {
        $email = strtolower((string) ($this->argument('email') ?: $this->ask('Email address')));
        $name = (string) $this->ask('Full name');
        $password = (string) $this->secret('Password');
        $confirmation = (string) $this->secret('Confirm password');
        $validator = validator(compact('email', 'name', 'password', 'confirmation'), [
            'email' => ['required', 'email'], 'name' => ['required', 'string', 'max:255'],
            'password' => ['required', Password::defaults()], 'confirmation' => ['same:password'],
        ]);
        if ($validator->fails()) {
            foreach ($validator->errors()->all() as $error) {
                $this->error($error);
            }

            return self::FAILURE;
        }
        $existing = User::where('email', $email)->first();
        if ($existing && ! $this->confirm('This email exists. Update its name and password?')) {
            return self::FAILURE;
        }
        User::updateOrCreate(['email' => $email], ['name' => $name, 'password' => Hash::make($password), 'email_verified_at' => now()]);
        $this->info('Administrator is ready.');

        return self::SUCCESS;
    }
}
