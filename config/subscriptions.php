<?php

return [
    // manual: activation by super admin / signed internal webhook.
    // paytr: salon owners pay each period by card on the PayTR iFrame page.
    'provider' => env('SUBSCRIPTION_PAYMENT_PROVIDER', 'manual'),
    'webhook_secret' => env('SUBSCRIPTION_WEBHOOK_SECRET'),

    // PayTR Mağaza Paneli > Destek & Kurulum > Entegrasyon Bilgileri.
    // Bildirim URL: https://<alan-adi>/webhooks/subscription-payments/paytr
    'paytr' => [
        'merchant_id' => env('PAYTR_MERCHANT_ID'),
        'merchant_key' => env('PAYTR_MERCHANT_KEY'),
        'merchant_salt' => env('PAYTR_MERCHANT_SALT'),
        'test_mode' => (bool) env('PAYTR_TEST_MODE', true),
        'debug' => (bool) env('PAYTR_DEBUG', false),
        'timeout_limit' => (int) env('PAYTR_TIMEOUT_MINUTES', 30),
        'no_installment' => true, // subscription periods are paid in a single installment
        'max_installment' => 0,
        'base_url' => env('PAYTR_BASE_URL', 'https://www.paytr.com'),
    ],

    'grace_period_days' => 7,
    'past_due_days' => 3,
    'invoice_prefix' => 'KF',
    'tax_rate_percent' => 20, // KDV; adjust per accounting advice

    // Feature keys a plan can enable.
    'features' => [
        'whatsapp' => 'WhatsApp bildirimleri',
        'reports' => 'Raporlar',
        'reviews' => 'Değerlendirmeler',
        'custom_theme' => 'Özel tema',
        'public_listing' => 'Platformda listelenme',
    ],
];
