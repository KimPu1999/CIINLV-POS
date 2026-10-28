<?php

namespace App\Mail;

use App\Models\MarketplaceContactInquiry;
use App\Models\MarketplaceContactInquiryMessage;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class MarketplaceContactInquiryMail extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(
        public readonly MarketplaceContactInquiry $inquiry,
        public readonly ?MarketplaceContactInquiryMessage $reply = null,
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(subject: $this->reply ? 'Reply from '.$this->inquiry->store?->name : 'New Marketplace contact inquiry: '.$this->inquiry->subject);
    }

    public function content(): Content
    {
        return new Content(view: 'emails.marketplace-contact-inquiry');
    }
}
