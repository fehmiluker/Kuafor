<?php

namespace App\Http\Controllers\Web\Tenant;

use App\Http\Middleware\SecurityHeaders;
use App\Models\SubscriptionCheckout;
use App\Services\Billing\CheckoutService;
use App\Services\Billing\EntitlementService;
use App\Services\Billing\HostedCheckoutGateway;
use App\Services\Billing\PaymentGatewayInterface;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

/** Owner pays the next subscription period by card on the provider's hosted page (PayTR iFrame). */
class SubscriptionPaymentController extends PanelBaseController
{
    public function start(Request $request, PaymentGatewayInterface $gateway, EntitlementService $entitlements, CheckoutService $checkouts): RedirectResponse
    {
        $this->need($request, 'subscriptions.manage');
        abort_unless($gateway instanceof HostedCheckoutGateway, 404);
        $sub = $entitlements->subscription($this->tenant()) ?? abort(404);

        $checkout = $checkouts->start($gateway, $sub, $request->user(), (string) $request->ip(),
            route('tenant.subscription', ['odeme' => 'tamamlandi']), route('tenant.subscription', ['odeme' => 'basarisiz']));

        return redirect()->route('tenant.subscription.pay.show', $checkout->merchant_oid);
    }

    public function show(Request $request, string $oid, PaymentGatewayInterface $gateway): Response|RedirectResponse
    {
        $this->need($request, 'subscriptions.manage');
        abort_unless($gateway instanceof HostedCheckoutGateway, 404);
        $checkout = SubscriptionCheckout::where('tenant_id', $this->tenant()->id)->where('merchant_oid', $oid)->firstOrFail();
        if (! $checkout->isPending() || $checkout->expires_at?->isPast() || ! $checkout->provider_token) {
            return redirect()->route('tenant.subscription')->with('status', $checkout->status === 'succeeded'
                ? 'Bu ödeme zaten tamamlandı.' : 'Ödeme oturumu sona erdi, lütfen yeniden başlatın.');
        }

        return response()->view('tenant.subscription-pay', [
            'checkout' => $checkout,
            'iframeUrl' => $gateway->iframeUrl($checkout->provider_token),
            'resizerUrl' => $gateway->name() === 'paytr' ? $gateway->cspOrigins()[0].'/js/iframeResizer.min.js' : null,
        ])->header('Content-Security-Policy', SecurityHeaders::policy($gateway->cspOrigins()));
    }
}
