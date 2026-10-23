<?php

namespace App\Console\Commands;

use App\Models\User;
use App\Services\AuditLogger;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rules\Password;

class ResetSuperAdminPassword extends Command
{
    protected $signature = 'ciinlv:reset-super-admin-password {email? : Existing Super Admin email address}';

    protected $description = 'Reset an existing Super Admin password using a hidden interactive prompt';

    public function handle(AuditLogger $auditLogger): int
    {
        $email = strtolower(trim((string) ($this->argument('email') ?: $this->ask('Email'))));
        $user = User::query()->where('email', $email)->first();

        if (! $user) {
            $this->error('No account exists for that email address.');

            return self::FAILURE;
        }

        if (! $user->isSuperAdmin()) {
            $this->error('The account is not a Super Admin. No changes were made.');

            return self::FAILURE;
        }

        $password = (string) $this->secret('New password');
        $confirmation = (string) $this->secret('Confirm new password');
        $validator = Validator::make([
            'password' => $password,
            'confirmation' => $confirmation,
        ], [
            'password' => ['required', 'same:confirmation', Password::min(8)->mixedCase()->numbers()->symbols()->uncompromised()],
            'confirmation' => ['required'],
        ]);

        if ($validator->fails()) {
            foreach ($validator->errors()->all() as $error) {
                $this->error($error);
            }

            return self::FAILURE;
        }

        if (! $this->confirm('Reset this Super Admin password?', false)) {
            $this->warn('No changes made.');

            return self::SUCCESS;
        }

        $revokedTokens = 0;
        DB::transaction(function () use ($user, $password, $auditLogger, &$revokedTokens): void {
            $user->forceFill([
                'password' => Hash::make($password),
                'remember_token' => null,
            ])->save();

            $revokedTokens = $user->tokens()->delete();
            $auditLogger->record('auth.super_admin_password_reset', $user, subject: $user, metadata: [
                'revoked_token_count' => $revokedTokens,
            ]);
        });

        $this->info('Super Admin password reset successfully.');
        $this->line('Existing Sanctum tokens were revoked and remember-me authentication was cleared.');
        $this->line('File-backed web sessions cannot be selectively identified by user; sign out any open browser sessions manually.');

        return self::SUCCESS;
    }
}
