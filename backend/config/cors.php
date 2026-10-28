<?php

return [
    'paths' => ['api/*', 'sanctum/csrf-cookie'],
    'allowed_methods' => array_values(array_filter(array_map('trim', explode(',', (string) env('CORS_ALLOWED_METHODS', 'GET,POST,PUT,PATCH,DELETE,OPTIONS'))))),
    'allowed_origins' => array_values(array_filter(array_map('trim', explode(',', (string) env('CORS_ALLOWED_ORIGINS', 'http://localhost:3000,http://localhost:5173,http://localhost:5175,http://127.0.0.1:5175'))))),
    'allowed_origins_patterns' => [],
    'allowed_headers' => array_values(array_filter(array_map('trim', explode(',', (string) env('CORS_ALLOWED_HEADERS', 'Accept,Authorization,Content-Type,X-CSRF-TOKEN,X-XSRF-TOKEN,X-Requested-With,Idempotency-Key,X-Tenant-Id'))))),
    'exposed_headers' => [],
    'max_age' => 0,
    'supports_credentials' => (bool) env('CORS_SUPPORTS_CREDENTIALS', true),
];
