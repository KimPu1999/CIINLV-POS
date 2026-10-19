<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Requests\Auth\LoginRequest;
use App\Http\Requests\Auth\UpdateProfileRequest;
use App\Http\Resources\UserResource;
use App\Models\User;
use App\Services\AuditLogger;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;

class AuthController
{
    public function login(LoginRequest $request, AuditLogger $auditLogger): JsonResponse
    {
        $data = $request->validated();
        $user = User::query()->where('email', $data['email'])->first();

        if (! $user || ! Hash::check($data['password'], $user->password)) {
            $auditLogger->record('auth.login_failed', $user, metadata: [
                'reason' => 'invalid_credentials',
                'email_hash' => hash('sha256', $data['email']),
                'ip_address' => $request->ip(),
                'user_agent' => mb_substr((string) $request->userAgent(), 0, 500),
            ]);

            throw ValidationException::withMessages(['email' => ['The provided credentials are incorrect.']]);
        }

        if (! $user->isActive()) {
            $auditLogger->record('auth.login_blocked', $user, metadata: ['status' => $user->status, 'ip_address' => $request->ip(), 'user_agent' => mb_substr((string) $request->userAgent(), 0, 500)]);
            abort(403, 'This account is not active.');
        }

        if (config('auth.require_verified_email', false) && ! $user->hasVerifiedEmail()) {
            abort(403, 'Your email address must be verified before signing in.');
        }

        $user->forceFill([
            'last_login_at' => now(),
            'last_login_ip' => $request->ip(),
        ])->save();

        $response = ['success' => true, 'data' => new UserResource($this->withActiveMemberships($user))];

        if ($data['client_type'] === 'mobile') {
            $expiresAt = now()->addDays((int) env('SANCTUM_MOBILE_TOKEN_DAYS', 30));
            $response['token'] = $user->createToken($data['device_name'], ['auth:mobile'], $expiresAt)->plainTextToken;
            $response['token_expires_at'] = $expiresAt;
        } else {
            Auth::guard('web')->login($user);
            $request->session()->regenerate();
        }

        $auditLogger->record('auth.login_succeeded', $user, metadata: ['client_type' => $data['client_type'], 'ip_address' => $request->ip(), 'user_agent' => mb_substr((string) $request->userAgent(), 0, 500)]);

        return response()->json($response);
    }

    public function logout(Request $request, AuditLogger $auditLogger): JsonResponse
    {
        $user = $request->user();

        if ($user) {
            $auditLogger->record('auth.logout', $user, metadata: [
                'client_type' => $request->user()->currentAccessToken() ? 'mobile' : 'web',
                'ip_address' => $request->ip(),
                'user_agent' => mb_substr((string) $request->userAgent(), 0, 500),
            ]);
        }

        if ($token = $request->user()?->currentAccessToken()) {
            $token->delete();
        }

        if (Auth::guard('web')->check()) {
            Auth::guard('web')->logout();
            if ($request->hasSession()) {
                $request->session()->invalidate();
                $request->session()->regenerateToken();
            }
        }

        return response()->json(['success' => true, 'message' => 'Logged out successfully.']);
    }

    public function me(Request $request): JsonResponse
    {
        return response()->json(['success' => true, 'data' => new UserResource($this->withActiveMemberships($request->user()))]);
    }

    public function updateProfile(UpdateProfileRequest $request, AuditLogger $auditLogger): JsonResponse
    {
        $user = $request->user();
        $user->forceFill(['name' => $request->validated('name')])->save();
        $auditLogger->record('auth.profile_updated', $user);
        return response()->json(['success' => true, 'data' => new UserResource($user->refresh())]);
    }

    private function withActiveMemberships(User $user): User
    {
        return $user->load(['memberships' => function ($query): void {
            $query->where('status', 'active')
                ->whereHas('tenant', fn ($tenantQuery) => $tenantQuery->where('status', 'active'))
                ->with(['tenant', 'role']);
        }]);
    }
}
