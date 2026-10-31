<?php

namespace App\Http\Resources;

use App\Support\LocalTime;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** Staff/customer view of an appointment. `internal_note` only with $this->staff = true. */
class AppointmentResource extends JsonResource
{
    public bool $staff = false;

    public function forStaff(): static
    {
        $this->staff = true;

        return $this;
    }

    public function toArray(Request $request): array
    {
        $tz = $this->branch?->timezone ?? config('tenancy.default_timezone');

        return [
            'id' => $this->id,
            'reference' => $this->public_reference,
            'status' => $this->status->value,
            'status_label' => $this->status->label(),
            'branch' => $this->whenLoaded('branch', fn () => ['id' => $this->branch->id, 'name' => $this->branch->name]),
            'employee' => $this->whenLoaded('employee', fn () => $this->employee ? ['id' => $this->employee->id, 'name' => $this->employee->display_name] : null),
            'customer' => $this->when($this->staff && $this->relationLoaded('customer'), fn () => [
                'id' => $this->customer->id, 'full_name' => $this->customer->full_name,
                'phone' => $this->customer->phone_e164, 'email' => $this->customer->email,
            ]),
            'services' => $this->whenLoaded('items', fn () => $this->items->map(fn ($i) => [
                'service_id' => $i->service_id, 'name' => $i->service_name_snapshot,
                'duration_minutes' => $i->duration_minutes_snapshot, 'price' => $i->unit_price_snapshot->jsonSerialize(),
            ])),
            'starts_at' => $this->starts_at_utc->toIso8601ZuluString(),
            'ends_at' => $this->ends_at_utc->toIso8601ZuluString(),
            'starts_at_local' => LocalTime::toLocal($this->starts_at_utc, $tz)->toIso8601String(),
            'timezone' => $tz,
            'subtotal' => $this->subtotal->jsonSerialize(),
            'discount' => $this->discount->jsonSerialize(),
            'total' => $this->total_price->jsonSerialize(),
            'payment_note' => 'Ödeme salonda yapılır.',
            'customer_note' => $this->customer_note,
            'internal_note' => $this->when($this->staff, $this->internal_note),
            'pending_expires_at' => $this->pending_expires_at_utc?->toIso8601ZuluString(),
            'open_proposal' => $this->whenLoaded('openProposal', fn () => $this->openProposal ? [
                'id' => $this->openProposal->id,
                'starts_at' => $this->openProposal->proposed_start_utc->toIso8601ZuluString(),
                'employee_id' => $this->openProposal->employee_id,
                'expires_at' => $this->openProposal->expires_at_utc->toIso8601ZuluString(),
            ] : null),
            'payments' => $this->when($this->staff && $this->relationLoaded('payments'), fn () => $this->payments->map(fn ($p) => [
                'amount' => $p->amount->jsonSerialize(), 'method' => $p->method->value, 'paid_at' => $p->paid_at->toIso8601ZuluString(),
            ])),
            'source' => $this->source->value,
            'created_at' => $this->created_at?->toIso8601ZuluString(),
        ];
    }
}
