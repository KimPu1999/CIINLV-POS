<?php

return [
    'online_payments_enabled' => (bool) env('MARKETPLACE_ONLINE_PAYMENTS_ENABLED', false),
    'webhook_secret' => env('MARKETPLACE_PAYMENT_WEBHOOK_SECRET'),
    'disputes' => [
        'filing_window_days' => (int) env('MARKETPLACE_DISPUTE_FILING_WINDOW_DAYS', 30),
        'max_evidence_files' => (int) env('MARKETPLACE_DISPUTE_MAX_EVIDENCE_FILES', 10),
        'max_evidence_size_kb' => (int) env('MARKETPLACE_DISPUTE_MAX_EVIDENCE_SIZE_KB', 10240),
    ],
];
