<?php

namespace App\Http\Controllers\Webhooks;

use App\Http\Controllers\Controller;
use App\Models\Subscription;
use App\Services\Billing\PaymentGatewayInterface;
use App\Services\Billing\SubscriptionService;
use App\Support\Money;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** Subscription (SaaS) payment provider callbacks — signature-verified and idempotent. */
class SubscriptionPaymentWebhookController extends Controller
{
    public function __invoke(Request $request, string $provider, PaymentGatewayInterface $gateway, SubscriptionService $subscriptions): JsonResponse
    {
        if ($provider !== $gateway->name() || ! $gateway->verifyWebhook($request)) {
            return response()->json(['error' => 'invalid_signature'], 401);
        }
        $event = $gateway->parseWebhook($request);
        if ($event['event_id'] === '' || ! ($sub = Subscription::find($event['subscription_id']))) {
            return response()->json(['error' => 'invalid_payload'], 422);
        }
        if ($event['status'] === 'succeeded') {
            $payment = $subscriptions->recordPayment($sub, $provider.':'.$event['event_id'],
                $event['amount'] !== null ? Money::parse($event['amount']) : null, $provider, $event['reference'], $event['event_id']);

            return response()->json(['ok' => true, 'payment_id' => $payment->id]);
        }

        return response()->json(['ok' => true, 'ignored' => $event['status']]);
    }
}
