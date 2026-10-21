<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Requests\Marketplace\CustomerLoginRequest;
use App\Http\Requests\Marketplace\CustomerRegisterRequest;
use App\Http\Requests\Marketplace\UpdateCustomerProfileRequest;
use App\Http\Resources\CustomerResource;
use App\Models\CustomerProfile;
use App\Models\User;
use App\Services\AuditLogger;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;

class MarketplaceCustomerAuthController
{
    public function register(CustomerRegisterRequest $request, AuditLogger $auditLogger): JsonResponse
    {
        $data = $request->validated();
        try {
            $user = DB::transaction(function () use ($data): User {
                $user = User::create([
                    'name' => $data['name'],
                    'email' => $data['email'],
                    'password' => Hash::make($data['password']),
                    'status' => User::STATUS_ACTIVE,
                    'locale' => app()->getLocale(),
                ]);
                CustomerProfile::create(['user_id' => $user->id, 'phone' => $data['phone'] ?? null, 'status' => 'active']);

                return $user;
            });
        } catch (UniqueConstraintViolationException) {
            return response()->json([
                'success' => false,
                'message' => 'An account with this email address already exists.',
                'errors' => ['email' => ['This email address is already registered.']],
            ], 409);
        } catch (\Throwable $exception) {
            Log::error('Marketplace customer registration transaction failed.', [
                'exception' => $exception::class,
                'message' => $exception->getMessage(),
            ]);

            return response()->json([
                'success' => false,
                'message' => 'We could not create your customer account right now. Please try again shortly.',
            ], 500);
        }

        Auth::guard('web')->login($user);
        $request->session()->regenerate();
        $auditLogger->record('marketplace.customer_registered', $user, metadata: ['ip_address' => $request->ip()]);

        return response()->json(['success' => true, 'data' => new CustomerResource($user->load(['customerProfile.addresses']))], 201);
    }

    public function login(CustomerLoginRequest $request, AuditLogger $auditLogger): JsonResponse
    {
        $data = $request->validated();
        $user = User::query()->where('email', $data['email'])->with('customerProfile')->first();

        if (! $user || ! $user->customerProfile || ! Hash::check($data['password'], $user->password)) {
            $auditLogger->record('marketplace.customer_login_failed', $user, metadata: ['email_hash' => hash('sha256', $data['email']), 'ip_address' => $request->ip()]);
            throw ValidationException::withMessages(['email' => ['The provided customer credentials are incorrect.']]);
        }

        if (! $user->isActive() || ! $user->customerProfile->isActive()) {
            abort(403, 'This customer account is not active.');
        }

        $user->forceFill(['last_login_at' => now(), 'last_login_ip' => $request->ip()])->save();
        Auth::guard('web')->login($user);
        $request->session()->regenerate();
        $auditLogger->record('marketplace.customer_login_succeeded', $user, metadata: ['ip_address' => $request->ip()]);

        return response()->json(['success' => true, 'data' => new CustomerResource($user->load(['customerProfile.addresses']))]);
    }

    public function logout(Request $request, AuditLogger $auditLogger): JsonResponse
    {
        $user = $request->user();
        $auditLogger->record('marketplace.customer_logout', $user, metadata: ['ip_address' => $request->ip()]);
        Auth::guard('web')->logout();
        if ($request->hasSession()) {
            $request->session()->invalidate();
            $request->session()->regenerateToken();
        }

        return response()->json(['success' => true, 'message' => 'Logged out successfully.']);
    }

    public function me(Request $request): JsonResponse
    {
        $user = $request->user()->load(['customerProfile.addresses']);

        return response()->json(['success' => true, 'data' => new CustomerResource($user)]);
    }

    public function update(UpdateCustomerProfileRequest $request, AuditLogger $auditLogger): JsonResponse
    {
        $user = $request->user();
        $data = $request->validated();
        $profileData = [];
        if (array_key_exists('phone', $data)) {
            $profileData['phone'] = $data['phone'];
            unset($data['phone']);
        }
        $user->update($data);
        if ($profileData !== []) {
            $user->customerProfile()->update($profileData);
        }
        $auditLogger->record('marketplace.customer_profile_updated', $user);

        return response()->json(['success' => true, 'data' => new CustomerResource($user->refresh()->load(['customerProfile.addresses']))]);
    }
}
