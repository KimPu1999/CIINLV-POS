<?php

namespace App\Jobs;

use App\Models\Tenant;
use App\Models\User;
use App\Notifications\BusinessEmailVerificationNotification;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Throwable;

class SendBusinessEmailVerification implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    public function __construct(public readonly int $userId, public readonly int $tenantId) {}

    public function handle(): void
    {
        User::query()->findOrFail($this->userId)->notify(
            new BusinessEmailVerificationNotification(Tenant::query()->findOrFail($this->tenantId)),
        );
    }

    public function failed(Throwable $exception): void
    {
        Log::error('Business email verification delivery failed.', [
            'user_id' => $this->userId,
            'tenant_id' => $this->tenantId,
            'exception' => $exception::class,
            'message' => $exception->getMessage(),
        ]);
    }
}
