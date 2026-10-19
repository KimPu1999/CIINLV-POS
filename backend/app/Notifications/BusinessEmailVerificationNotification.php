<?php

namespace App\Notifications;

use App\Models\Tenant;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;
use Illuminate\Support\Facades\URL;

class BusinessEmailVerificationNotification extends Notification
{
    public function __construct(private readonly Tenant $tenant) {}

    public function via(object $notifiable): array { return ['mail']; }

    public function toMail(object $notifiable): MailMessage
    {
        $url = URL::temporarySignedRoute(
            'verification.verify',
            now()->addMinutes((int) config('business.email_verification_expiry_minutes', 60)),
            [
                'id' => $notifiable->getKey(),
                'hash' => sha1($notifiable->getEmailForVerification()),
                'tenant_id' => $this->tenant->getKey(),
            ],
        );

        return (new MailMessage)
            ->subject('Verify your CIINLV POS business email')
            ->view('emails.business-email-verification', [
                'businessName' => $this->tenant->name,
                'ownerName' => $notifiable->name,
                'verificationUrl' => $url,
                'expiresAt' => now()->addMinutes((int) config('business.email_verification_expiry_minutes', 60)),
            ])
            ->text('emails.business-email-verification-text', [
                'businessName' => $this->tenant->name,
                'verificationUrl' => $url,
                'expiresAt' => now()->addMinutes((int) config('business.email_verification_expiry_minutes', 60)),
            ]);
    }
}
