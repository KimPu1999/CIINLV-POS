<?php

namespace App\Jobs;

use App\Models\BusinessInvitation;
use App\Notifications\BusinessInvitationNotification;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Notification;

class SendBusinessInvitation implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public function __construct(
        public readonly int $invitationId,
        public readonly string $encryptedToken,
    ) {}

    public function handle(): void
    {
        $invitation = BusinessInvitation::query()->with('tenant')->findOrFail($this->invitationId);

        if (! $invitation->isPending()) {
            return;
        }

        Notification::route('mail', $invitation->email)
            ->notify(new BusinessInvitationNotification($invitation, Crypt::decryptString($this->encryptedToken)));
    }
}
