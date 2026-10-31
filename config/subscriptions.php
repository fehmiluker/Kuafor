<?php

return [
    // manual: activation by super admin / test mode. Real gateways implement
    // App\Services\Billing\PaymentGatewayInterface and are registered here.
    'provider' => env('SUBSCRIPTION_PAYMENT_PROVIDER', 'manual'),
    'webhook_secret' => env('SUBSCRIPTION_WEBHOOK_SECRET'),

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
