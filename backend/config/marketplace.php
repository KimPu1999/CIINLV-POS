<?php

return [
    'online_payments_enabled' => (bool) env('MARKETPLACE_ONLINE_PAYMENTS_ENABLED', false),
    'webhook_secret' => env('MARKETPLACE_PAYMENT_WEBHOOK_SECRET'),
];
