<?php

return [
    // fake: no network, messages are recorded and marked sent (dev/test/sandbox).
    // meta: official Meta WhatsApp Business Platform Cloud API.
    'mode' => env('WHATSAPP_PROVIDER_MODE', 'fake'),

    'graph_base_url' => env('META_GRAPH_BASE_URL', 'https://graph.facebook.com'),
    'graph_version' => env('META_GRAPH_API_VERSION', 'v21.0'),
    'app_id' => env('META_APP_ID'),
    'app_secret' => env('META_APP_SECRET'),
    'verify_token' => env('META_VERIFY_TOKEN'),
    'timeout_seconds' => 10,

    // Embedded Signup ("WhatsApp'ı bağla" button): configuration id from Meta App Dashboard
    // (Facebook Login for Business -> Configurations, WhatsApp Embedded Signup).
    'embedded_signup_config_id' => env('META_EMBEDDED_SIGNUP_CONFIG_ID'),

    // When a branch has no number of its own, send through the platform number.
    'platform_fallback' => env('WHATSAPP_PLATFORM_FALLBACK', true),

    // Free-form (non-template) messages are allowed only within this window after
    // the user's last inbound message (Meta customer service window).
    'service_window_hours' => 24,

    // Webhook payloads are stored redacted and purged after this many days.
    'webhook_retention_days' => 30,
    'message_retention_days' => 180,

    'action_token_ttl_minutes' => 240,

    // Provider fees are NOT hardcoded. Fill from your Meta/BSP contract for reporting.
    'pricing' => [
        'currency' => env('WHATSAPP_PRICING_CURRENCY', 'USD'),
        'utility_per_message' => env('WHATSAPP_PRICE_UTILITY'),
        'marketing_per_message' => env('WHATSAPP_PRICE_MARKETING'),
        'bsp_fee_per_message' => env('WHATSAPP_BSP_FEE'),
    ],
];
