<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class SubscriptionResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'tenant_id' => $this->tenant_id,
            'status' => $this->status->value,
            'status_label' => $this->status->label(),
            'plan' => new PlanResource($this->whenLoaded('plan')),
            'pending_plan' => $this->whenLoaded('pendingPlan', fn () => $this->pendingPlan ? ['slug' => $this->pendingPlan->slug, 'name' => $this->pendingPlan->name] : null),
            'billing_interval' => $this->billing_interval->value,
            'price' => $this->price_snapshot->jsonSerialize(),
            'entitlements' => $this->entitlements_snapshot,
            'trial_ends_at' => $this->trial_ends_at?->toIso8601ZuluString(),
            'current_period_start' => $this->current_period_start?->toIso8601ZuluString(),
            'current_period_end' => $this->current_period_end?->toIso8601ZuluString(),
            'grace_ends_at' => $this->grace_ends_at?->toIso8601ZuluString(),
            'cancel_at_period_end' => $this->cancel_at_period_end,
            'provider' => $this->provider,
        ];
    }
}
