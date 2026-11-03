<?php

namespace App\Services\Billing;

use App\Services\Settings\SettingsRepository;

final class PaymentSettings
{
    public function __construct(private readonly SettingsRepository $settings) {}

    public function provider(): string
    {
        return (string) ($this->settings->platform()['subscription_provider'] ?? config('subscriptions.provider', 'manual'));
    }

    public function paytr(): array
    {
        $s = $this->settings->platform();
        $config = (array) config('subscriptions.paytr');
        foreach (['merchant_id', 'merchant_key', 'merchant_salt', 'test_mode'] as $key) {
            if (array_key_exists('paytr_'.$key, $s)) { $config[$key] = $s['paytr_'.$key]; }
        }
        return $config;
    }
}
