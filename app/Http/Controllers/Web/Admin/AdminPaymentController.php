<?php

namespace App\Http\Controllers\Web\Admin;

use App\Http\Controllers\Controller;
use App\Services\Audit\AuditLogger;
use App\Services\Billing\PaymentSettings;
use App\Services\Settings\SettingsRepository;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class AdminPaymentController extends Controller
{
    private function need(Request $request): void
    {
        abort_unless($request->user()->hasPlatformPermission('platform.settings'), 403);
    }

    public function edit(Request $request, PaymentSettings $payments)
    {
        $this->need($request);
        $config = $payments->paytr();
        return view('admin.payments', ['provider' => $payments->provider(), 'merchantId' => $config['merchant_id'] ?? '', 'hasKey' => filled($config['merchant_key'] ?? null), 'hasSalt' => filled($config['merchant_salt'] ?? null), 'testMode' => (bool) ($config['test_mode'] ?? true)]);
    }

    public function update(Request $request, SettingsRepository $settings, PaymentSettings $payments)
    {
        $this->need($request);
        $d = $request->validate([
            'provider' => ['required', Rule::in(['manual', 'paytr'])], 'merchant_id' => ['nullable', 'regex:/^[0-9]{1,30}$/'],
            'merchant_key' => ['nullable', 'string', 'max:200'], 'merchant_salt' => ['nullable', 'string', 'max:200'],
            'test_mode' => ['nullable', 'boolean'],
        ]);
        $current = $payments->paytr();
        $values = ['subscription_provider' => $d['provider'], 'paytr_test_mode' => $request->boolean('test_mode')];
        foreach (['merchant_id', 'merchant_key', 'merchant_salt'] as $key) {
            if (filled($d[$key] ?? null)) { $values['paytr_'.$key] = $d[$key]; $current[$key] = $d[$key]; }
        }
        if ($d['provider'] === 'paytr' && (blank($current['merchant_id'] ?? null) || blank($current['merchant_key'] ?? null) || blank($current['merchant_salt'] ?? null))) {
            throw ValidationException::withMessages(['provider' => 'PayTR açılmadan önce mağaza numarası, anahtar ve salt tanımlanmalı.']);
        }
        DB::transaction(function () use ($settings, $values) {
            $settings->setPlatform($values, ['paytr_merchant_key', 'paytr_merchant_salt']);
            app(AuditLogger::class)->log('admin.payment_settings_saved', null, [], ['provider' => $values['subscription_provider'], 'test_mode' => $values['paytr_test_mode']]);
        });
        return back()->with('status', 'Ödeme ayarları kaydedildi. Bu işlem ödeme testi yapmaz.');
    }
}
