<?php

namespace App\Notifications;

use App\Models\MarketplaceCancellationRequest;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class MarketplaceCancellationDecisionNotification extends Notification
{
    use Queueable;

    public function __construct(private readonly MarketplaceCancellationRequest $request) {}

    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $approved = $this->request->status === 'approved';
        $subject = $approved ? 'Your CIINLV POS cancellation request was approved' : 'Your CIINLV POS cancellation request was rejected';
        $message = $approved
            ? 'Your cancellation request for order '.$this->request->order?->order_number.' has been approved.'
            : 'Your cancellation request for order '.$this->request->order?->order_number.' has been rejected.';

        return (new MailMessage)
            ->subject($subject)
            ->greeting('Hello '.$notifiable->name.',')
            ->line($message)
            ->line($this->request->decision_note ? 'Decision note: '.$this->request->decision_note : '');
    }
}
