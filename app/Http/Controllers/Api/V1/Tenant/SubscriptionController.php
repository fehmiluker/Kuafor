<?php

namespace App\Http\Controllers\Api\V1\Tenant;

use App\Enums\BillingInterval;
use App\Http\Resources\PlanResource;
use App\Http\Resources\SubscriptionResource;
use App\Http\Responses\ApiResponse;
use App\Models\Plan;
use App\Services\Billing\EntitlementService;
use App\Services\Billing\HostedCheckoutGateway;
use App\Services\Billing\PaymentGatewayInterface;
use App\Services\Billing\SubscriptionService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class SubscriptionController extends TenantApiController
{
    public function show(Request $request, EntitlementService $entitlements, PaymentGatewayInterface $gateway): JsonResponse
    {
        $sub = $entitlements->subscription($this->tenant())?->load(['plan.features', 'pendingPlan']);

        return ApiResponse::ok($sub ? new SubscriptionResource($sub) : null, [
            'usage' => $entitlements->usage($this->tenant()),
            'over_limit' => $entitlements->overLimit($this->tenant()),
            'plans' => PlanResource::collection(Plan::with('features')->where('active', true)->where('public', true)->orderBy('sort_order')->get())->resolve($request),
            'automatic_payment_available' => $gateway->supportsAutomaticCharging(),
            'card_payment_available' => $gateway instanceof HostedCheckoutGateway, // web panel: /panel/abonelik
        ]);
    }

    public function changePlan(Request $request, EntitlementService $entitlements, SubscriptionService $subscriptions): JsonResponse
    {
        abort_unless($request->user()->inTeam($this->tenant()->id, fn ($u) => $u->hasPermissionTo('subscriptions.manage')), 403);
        $data = $request->validate([
            'plan' => ['required', Rule::exists('plans', 'slug')->where('active', true)->whereNull('deleted_at')],
            'interval' => ['required', Rule::in(BillingInterval::values())],
        ]);
        $sub = $entitlements->subscription($this->tenant()) ?? abort(404);
        $plan = Plan::with('features')->where('slug', $data['plan'])->firstOrFail();
        $sub = $subscriptions->changePlan($sub->load('plan'), $plan, BillingInterval::from($data['interval']), $request->user()->id);

        return ApiResponse::ok(new SubscriptionResource($sub->load(['plan.features', 'pendingPlan'])), [
            'over_limit' => $entitlements->overLimit($this->tenant()),
            'message' => $sub->pending_plan_id ? 'Paket değişikliği bir sonraki dönem başında uygulanacak.' : 'Paketiniz güncellendi.',
        ]);
    }
}
