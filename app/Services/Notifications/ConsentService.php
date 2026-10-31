<?php

namespace App\Services\Notifications;

use App\Enums\ConsentStatus;
use App\Enums\NotificationCategory;
use App\Enums\NotificationChannel;
use App\Models\CommunicationConsent;
use App\Models\NotificationPreference;
use App\Models\TenantCustomer;

/**
 * Consents are recorded per subject + tenant + purpose + channel with proof.
 * Transactional/reminder WhatsApp messages need the customer's opt-in to be
 * contacted on WhatsApp (Meta policy); marketing requires a separate, explicit
 * consent (İYS/ETK) and is never implied by a booking.
 */
final class ConsentService
{
    public function grant(TenantCustomer $customer, NotificationCategory $purpose, NotificationChannel $channel, string $source, array $proof = []): void
    {
        $existing = $this->current($customer, $purpose, $channel);
        if ($existing && $existing->status === ConsentStatus::Granted) {
            return;
        }
        CommunicationConsent::create([
            'subject_type' => 'tenant_customer', 'subject_id' => $customer->id, 'tenant_id' => $customer->tenant_id,
            'purpose' => $purpose->value, 'channel' => $channel->value, 'status' => ConsentStatus::Granted,
            'source' => $source, 'captured_at' => now(), 'proof_metadata' => $proof + ['text_version' => config('app.consent_text_version', '2026-01')],
        ]);
        if ($purpose === NotificationCategory::Marketing) {
            $customer->forceFill(['marketing_consent_at' => now()])->save();
        }
    }

    public function withdraw(TenantCustomer $customer, NotificationCategory $purpose, NotificationChannel $channel, string $source): void
    {
        $existing = $this->current($customer, $purpose, $channel);
        if ($existing && $existing->status === ConsentStatus::Granted) {
            $existing->forceFill(['status' => ConsentStatus::Withdrawn, 'withdrawn_at' => now(), 'source' => $source])->save();
        }
        if ($purpose === NotificationCategory::Marketing) {
            $customer->forceFill(['marketing_consent_at' => null])->save();
        }
    }

    public function has(TenantCustomer $customer, NotificationCategory $purpose, NotificationChannel $channel): bool
    {
        // Reminders and review invites ride on the transactional WhatsApp opt-in.
        $basis = in_array($purpose, [NotificationCategory::Reminder, NotificationCategory::ReviewInvite], true) ? NotificationCategory::Transactional : $purpose;
        $consent = $this->current($customer, $basis, $channel);
        if (! $consent || $consent->status !== ConsentStatus::Granted) {
            return false;
        }

        return $this->preferenceAllows($customer, $purpose, $channel);
    }

    public function preferenceAllows(TenantCustomer $customer, NotificationCategory $purpose, NotificationChannel $channel): bool
    {
        $pref = NotificationPreference::query()
            ->where(fn ($q) => $q->where('tenant_customer_id', $customer->id)->when($customer->user_id, fn ($w) => $w->orWhere('user_id', $customer->user_id)))
            ->where('channel', $channel->value)->where('category', $purpose->value)->first();

        return $pref?->enabled ?? true;
    }

    private function current(TenantCustomer $customer, NotificationCategory $purpose, NotificationChannel $channel): ?CommunicationConsent
    {
        return CommunicationConsent::where('subject_type', 'tenant_customer')->where('subject_id', $customer->id)
            ->where('tenant_id', $customer->tenant_id)->where('purpose', $purpose->value)->where('channel', $channel->value)
            ->latest('id')->first();
    }
}
