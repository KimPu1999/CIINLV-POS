<?php

return [
    'allow_manual_payment_verification' => (bool) env('POS_ALLOW_MANUAL_PAYMENT_VERIFICATION', false),
    'receipt_company_name' => env('POS_RECEIPT_COMPANY_NAME', 'CIINLV POS'),
    'receipt_footer' => env('POS_RECEIPT_FOOTER', 'Thank you for your business.'),
    'default_currency' => env('POS_DEFAULT_CURRENCY', 'MMK'),
    'default_tax_rate' => env('POS_DEFAULT_TAX_RATE', '0'),
    'payment_methods' => [
        'cash' => ['requires_external_verification' => false],
        'card_terminal' => ['requires_external_verification' => true],
        'qr' => ['requires_external_verification' => true],
        'bank_transfer' => ['requires_external_verification' => true],
    ],
];
