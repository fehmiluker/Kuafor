<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class TenantCustomerResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'full_name' => $this->full_name,
            'phone' => $this->phone_e164,
            'email' => $this->email,
            'status' => $this->status?->value,
            'has_account' => $this->user_id !== null,
            'marketing_consent' => $this->marketing_consent_at !== null,
            'last_visit_at' => $this->last_visit_at?->toIso8601ZuluString(),
            'notes' => $this->when((bool) ($this->showNotes ?? false), fn () => $this->notes),
            'appointments_count' => $this->whenCounted('appointments'),
        ];
    }
}
