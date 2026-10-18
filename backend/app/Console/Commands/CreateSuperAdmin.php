<?php

namespace App\Console\Commands;

use App\Models\User;
use App\Services\AuditLogger;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rules\Password;

class CreateSuperAdmin extends Command
{
    protected $signature = 'ciinlv:create-super-admin';

    protected $description = 'Provision a platform-scoped Super Admin account';

    public function handle(AuditLogger $auditLogger): int
    {
        $name = trim((string) $this->ask('Name'));
        $email = strtolower(trim((string) $this->ask('Email')));
        $password = (string) $this->secret('Password');
        $validator = Validator::make(compact('name', 'email', 'password'), [
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'email:rfc', 'max:255'],
            'password' => [Password::min(12)->mixedCase()->numbers()->symbols()->uncompromised()],
        ]);

        if ($validator->fails()) {
            foreach ($validator->errors()->all() as $error) {
                $this->error($error);
            }

            return self::FAILURE;
        }

        $existing = User::query()->where('email', $email)->first();
        if ($existing && ! $this->confirm('An account with this email exists. Grant it Super Admin access?', false)) {
            $this->warn('No changes made.');

            return self::SUCCESS;
        }

        if ($existing) {
            $existing->forceFill([
                'name' => $name,
                'platform_role' => User::PLATFORM_ROLE_SUPER_ADMIN,
                'status' => User::STATUS_ACTIVE,
                'email_verified_at' => $existing->email_verified_at ?: now(),
            ])->save();
            $auditLogger->record('platform.super_admin_granted', $existing, subject: $existing);
            $this->info('Existing account granted Super Admin access.');

            return self::SUCCESS;
        }

        $user = User::create([
            'name' => $name,
            'email' => $email,
            'password' => Hash::make($password),
            'platform_role' => User::PLATFORM_ROLE_SUPER_ADMIN,
            'status' => User::STATUS_ACTIVE,
            'email_verified_at' => now(),
        ]);
        $auditLogger->record('platform.super_admin_created', $user, subject: $user);
        $this->info('Super Admin account created.');

        return self::SUCCESS;
    }
}
