<?php

use App\Http\Controllers\Webhooks\MetaWhatsAppWebhookController;
use App\Http\Controllers\Webhooks\SubscriptionPaymentWebhookController;
use Illuminate\Support\Facades\Route;

// No session, no CSRF (signature-verified instead), rate limited.
Route::middleware('throttle:webhooks')->prefix('webhooks')->name('webhooks.')->group(function () {
    Route::get('meta/whatsapp', [MetaWhatsAppWebhookController::class, 'verify'])->name('meta.verify');
    Route::post('meta/whatsapp', [MetaWhatsAppWebhookController::class, 'receive'])->name('meta.receive');
    Route::post('subscription-payments/{provider}', SubscriptionPaymentWebhookController::class)->name('subscription-payments');
});
