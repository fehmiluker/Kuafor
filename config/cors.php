<?php

return [
    'paths' => ['api/*'],
    'allowed_methods' => ['GET', 'POST', 'PATCH', 'PUT', 'DELETE', 'OPTIONS'],
    'allowed_origins' => array_filter(explode(',', (string) env('CORS_ALLOWED_ORIGINS', ''))),
    'allowed_origins_patterns' => [],
    'allowed_headers' => ['Content-Type', 'Authorization', 'Accept', 'X-Tenant', 'Idempotency-Key', 'X-Requested-With'],
    'exposed_headers' => ['X-RateLimit-Remaining', 'Retry-After'],
    'max_age' => 600,
    'supports_credentials' => false,
];
