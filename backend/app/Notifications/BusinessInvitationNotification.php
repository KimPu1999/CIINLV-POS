<?php

namespace App\Notifications;

use App\Models\BusinessInvitation;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;
use Illuminate\Support\Facades\Crypt;

class BusinessInvitationNotification extends Notification
{
    use Queueable;

    public function __construct(
        private readonly BusinessInvitation $invitation,
        private readonly string $encryptedToken,
    ) {}

    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $plainToken = Crypt::decryptString($this->encryptedToken);
        $url = rtrim((string) config('app.frontend_url'), '/').'/accept-invitation/'.rawurlencode($plainToken).'?email='.rawurlencode($this->invitation->email);

        return (new MailMessage)
            ->subject('CIINLV POS business invitation')
            ->view('emails.business-invitation', [
                'businessName' => $this->invitation->tenant?->name ?? 'CIINLV POS business',
                'recipientEmail' => $this->invitation->email,
                'expiresAt' => $this->invitation->expires_at,
                'activationUrl' => $url,
            ])
            ->text('emails.business-invitation-text', [
                'businessName' => $this->invitation->tenant?->name ?? 'CIINLV POS business',
                'recipientEmail' => $this->invitation->email,
                'expiresAt' => $this->invitation->expires_at,
                'activationUrl' => $url,
            ]);
    }
}
