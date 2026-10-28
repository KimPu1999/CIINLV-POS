<?php

namespace App\Jobs;

use App\Mail\MarketplaceContactInquiryMail;
use App\Models\MarketplaceContactInquiry;
use App\Models\MarketplaceContactInquiryMessage;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Log;
use Throwable;

class SendMarketplaceContactEmail implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    public function __construct(public readonly string $kind, public readonly int $recordId, public readonly string $recipient) {}

    public function handle(): void
    {
        if ($this->kind === 'admin_notification') {
            $inquiry = MarketplaceContactInquiry::query()->findOrFail($this->recordId);
            Mail::to($this->recipient)->send(new MarketplaceContactInquiryMail($inquiry));
            $inquiry->forceFill([
                'admin_notification_status' => MarketplaceContactInquiry::DELIVERY_SENT,
                'admin_notification_error' => null,
                'admin_notified_at' => now(),
            ])->save();

            return;
        }

        $message = MarketplaceContactInquiryMessage::query()->with('inquiry.store')->findOrFail($this->recordId);
        Mail::to($this->recipient)->send(new MarketplaceContactInquiryMail($message->inquiry, $message));
        $message->forceFill([
            'delivery_status' => MarketplaceContactInquiry::DELIVERY_SENT,
            'delivery_error' => null,
            'sent_at' => now(),
        ])->save();
    }

    public function failed(Throwable $exception): void
    {
        $error = mb_substr($exception->getMessage(), 0, 1000);
        if ($this->kind === 'admin_notification') {
            MarketplaceContactInquiry::query()->whereKey($this->recordId)->update([
                'admin_notification_status' => MarketplaceContactInquiry::DELIVERY_FAILED,
                'admin_notification_error' => $error,
            ]);
        } else {
            MarketplaceContactInquiryMessage::query()->whereKey($this->recordId)->update([
                'delivery_status' => MarketplaceContactInquiry::DELIVERY_FAILED,
                'delivery_error' => $error,
            ]);
        }

        Log::error('Marketplace contact email delivery failed.', [
            'kind' => $this->kind,
            'record_id' => $this->recordId,
            'recipient' => $this->recipient,
            'exception' => $exception::class,
            'message' => $error,
        ]);
    }
}
