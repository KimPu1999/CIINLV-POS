<?php

namespace App\Notifications;

use App\Models\BusinessInvitation;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class BusinessInvitationNotification extends Notification
{
    use Queueable;

    public function __construct(
        private readonly BusinessInvitation $invitation,
        private readonly string $plainToken,
    ) {}

    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $url = rtrim((string) config('app.url'), '/').'/activate-business?email='.rawurlencode($this->invitation->email).'&token='.rawurlencode($this->plainToken);

        return (new MailMessage)
            ->subject('CIINLV POS business invitation')
            ->greeting('You have been invited to manage a CIINLV POS business.')
            ->line('Use the secure activation link to set your password and activate your business administrator account.')
            ->action('Activate business account', $url)
            ->line('This invitation expires on '.$this->invitation->expires_at->toIso8601String().'.');
    }
}
