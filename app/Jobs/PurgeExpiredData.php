<?php

namespace App\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Support\Facades\DB;

/** Retention policy (docs/security.md): webhook payloads, message rows, tokens, idempotency keys, home-visit addresses. */
class PurgeExpiredData implements ShouldBeUnique, ShouldQueue
{
    use Dispatchable, Queueable;

    public function handle(): void
    {
        DB::table('whatsapp_webhook_events')->where('received_at', '<', now()->subDays((int) config('whatsapp.webhook_retention_days')))->delete();
        DB::table('whatsapp_messages')->where('created_at', '<', now()->subDays((int) config('whatsapp.message_retention_days')))->update(['recipient_e164' => 'redacted', 'payload_redacted' => null]);
        DB::table('idempotency_keys')->where('expires_at', '<', now())->delete();
        DB::table('action_tokens')->where('expires_at', '<', now()->subDays(30))->delete();
        DB::table('guest_access_tokens')->where('expires_at', '<', now()->subDays(30))->delete();
        DB::table('phone_verifications')->where('expires_at', '<', now()->subDays(7))->delete();
        DB::table('resource_day_locks')->where('local_date', '<', now()->subDays(7)->toDateString())->delete();
        // Home visits: the street address is personal data only needed around the visit; the district is kept for reports.
        DB::table('appointment_addresses')->whereNull('purged_at')
            ->whereIn('appointment_id', DB::table('appointments')->where('ends_at_utc', '<', now()->subDays((int) config('booking.home_address_retention_days', 90)))->select('id'))
            ->update(['address_line' => null, 'address_detail' => null, 'purged_at' => now(), 'updated_at' => now()]);
    }
}
