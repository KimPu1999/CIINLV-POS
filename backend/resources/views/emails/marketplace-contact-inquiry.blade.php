@if ($reply)
Hello {{ $inquiry->name }},

{{ $reply->body }}

This is a reply from {{ $inquiry->store?->name ?: 'the marketplace support team' }}.
@else
New Marketplace contact inquiry

Business: {{ $inquiry->store?->name ?: 'Marketplace business' }}
Customer: {{ $inquiry->name }} ({{ $inquiry->email }})
Phone: {{ $inquiry->phone ?: 'Not provided' }}
Subject: {{ $inquiry->subject }}

{{ $inquiry->message }}
@endif
