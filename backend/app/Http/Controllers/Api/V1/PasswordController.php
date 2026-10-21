<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Requests\Auth\ChangePasswordRequest;
use App\Http\Requests\Auth\ForgotPasswordRequest;
use App\Http\Requests\Auth\ResetPasswordRequest;
use App\Models\User;
use App\Services\AuditLogger;
use Illuminate\Auth\Events\PasswordReset;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Password;
use Illuminate\Validation\ValidationException;

class PasswordController
{
    public function change(ChangePasswordRequest $request, AuditLogger $auditLogger): JsonResponse
    {
        $user = $request->user();
        if (! Hash::check($request->validated('current_password'), $user->password)) {
            throw ValidationException::withMessages(['current_password' => ['The current password is incorrect.']]);
        }

        $user->forceFill(['password' => Hash::make($request->validated('password'))])->save();
        $user->tokens()->delete();

        $auditLogger->record('auth.password_changed', $user);

        return response()->json(['success' => true, 'message' => 'Password changed successfully.']);
    }

    public function forgot(ForgotPasswordRequest $request, AuditLogger $auditLogger): JsonResponse
    {
        $user = User::query()->where('email', $request->validated('email'))->first();
        $status = $user?->isActive()
            ? Password::sendResetLink($request->only('email'))
            : Password::INVALID_USER;

        if ($status === Password::RESET_LINK_SENT) {
            $auditLogger->record('auth.password_reset_requested', metadata: ['email_hash' => hash('sha256', strtolower($request->validated('email')))]);
        }

        return response()->json([
            'success' => true,
            'message' => 'If an account matches that email, password reset instructions have been sent.',
        ]);
    }

    public function reset(ResetPasswordRequest $request, AuditLogger $auditLogger): JsonResponse
    {
        $status = Password::reset(
            $request->validated(),
            function ($user, string $password) use ($auditLogger): void {
                $user->forceFill([
                    'password' => Hash::make($password),
                ])->save();
                $user->tokens()->delete();
                event(new PasswordReset($user));
                $auditLogger->record('auth.password_reset_completed', $user);
            },
        );

        if ($status !== Password::PASSWORD_RESET) {
            throw ValidationException::withMessages(['token' => ['This password reset link is invalid or expired.']]);
        }

        return response()->json(['success' => true, 'message' => 'Password reset successfully.']);
    }
}
