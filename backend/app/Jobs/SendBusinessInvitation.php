<?php

namespace App\Jobs;

use App\Models\BusinessInvitation;
use App\Notifications\BusinessInvitationNotification;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Str;
use RuntimeException;
use Throwable;

class SendBusinessInvitation implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public function __construct(
        public readonly int $invitationId,
    ) {}

    public function handle(): void
    {
        $invitation = BusinessInvitation::query()->with('tenant')->findOrFail($this->invitationId);

        if (! $invitation->isPending()) {
            Cache::forget($invitation->deliverySecretCacheKey());

            return;
        }

        try {
            $encryptedToken = Cache::get($invitation->deliverySecretCacheKey());
            if (! is_string($encryptedToken) || $encryptedToken === '') {
                throw new RuntimeException('The invitation delivery secret is unavailable. Resend the invitation.');
            }

            Notification::route('mail', $invitation->email)
                ->notify(new BusinessInvitationNotification($invitation, $encryptedToken));

            $invitation->forceFill([
                'sent_at' => now(),
                'failed_at' => null,
                'failure_reason' => null,
            ])->save();
            Cache::forget($invitation->deliverySecretCacheKey());
        } catch (Throwable $exception) {
            $invitation->forceFill([
                'failed_at' => now(),
                'failure_reason' => Str::limit($exception->getMessage(), 1000, '...'),
            ])->saveQuietly();

            Log::error('Business invitation delivery failed after provisioning completed.', [
                'invitation_id' => $invitation->getKey(),
                'tenant_id' => $invitation->tenant_id,
                'exception' => $exception::class,
                'message' => $exception->getMessage(),
            ]);
        }
    }
}
