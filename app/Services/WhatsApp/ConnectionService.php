<?php

namespace App\Services\WhatsApp;

use App\Enums\WhatsAppConnectionStatus;
use App\Exceptions\DomainRuleException;
use App\Models\Branch;
use App\Models\WhatsAppConnection;
use App\Services\Audit\AuditLogger;
use App\Services\Billing\EntitlementService;
use App\Support\PhoneNumber;

/**
 * Branch <-> Meta number binding. In production the waba_id/phone_number_id and
 * token come from Embedded Signup (code exchange on the server). This service
 * validates them with a live health call before marking the branch connected.
 */
final class ConnectionService
{
    public function __construct(private readonly WhatsAppClientInterface $client, private readonly EntitlementService $entitlements, private readonly AuditLogger $audit) {}

    public function connect(Branch $branch, string $wabaId, string $phoneNumberId, string $accessToken, ?string $displayPhone = null): WhatsAppConnection
    {
        if (! $this->entitlements->hasFeature($branch->tenant, 'whatsapp')) {
            throw new DomainRuleException('WhatsApp entegrasyonu mevcut paketinizde yer almıyor.', 'feature_unavailable');
        }
        $taken = WhatsAppConnection::withoutGlobalScopes()->where('phone_number_id', $phoneNumberId)->where('branch_id', '!=', $branch->id)->exists();
        if ($taken) {
            throw new DomainRuleException('Bu WhatsApp numarası başka bir şubeye bağlı.', 'number_in_use');
        }
        $connection = WhatsAppConnection::withoutGlobalScopes()->firstOrNew(['branch_id' => $branch->id], ['tenant_id' => $branch->tenant_id]);
        $connection->forceFill([
            'tenant_id' => $branch->tenant_id,
            'waba_id' => $wabaId,
            'phone_number_id' => $phoneNumberId,
            'display_phone' => PhoneNumber::normalize($displayPhone),
            'encrypted_credential_reference' => $accessToken,
            'status' => WhatsAppConnectionStatus::Pending,
        ])->save();

        $health = $this->client->health($connection);
        if (! $health->ok) {
            $connection->forceFill(['status' => WhatsAppConnectionStatus::Error, 'last_error' => 'Doğrulama başarısız ('.$health->error.')', 'last_health_check_at' => now()])->save();
            throw new DomainRuleException('WhatsApp numarası doğrulanamadı. Erişim izni ve numara bilgilerini kontrol edin.', 'connection_failed');
        }
        $connection->forceFill([
            'status' => WhatsAppConnectionStatus::Connected,
            'display_phone' => $health->displayPhone ?? $connection->display_phone,
            'webhook_subscribed' => $this->client->subscribeWebhooks($connection),
            'connected_at' => now(),
            'last_health_check_at' => now(),
            'last_error' => null,
            'metadata' => ['quality_rating' => $health->qualityRating],
        ])->save();
        $this->audit->log('whatsapp.connected', $connection, [], ['branch_id' => $branch->id, 'phone_number_id' => $phoneNumberId]);

        return $connection;
    }

    /** Revocation: credential is wiped immediately; history rows stay. */
    public function disconnect(WhatsAppConnection $connection, string $reason = 'user'): void
    {
        $connection->forceFill([
            'status' => WhatsAppConnectionStatus::Disconnected,
            'encrypted_credential_reference' => null,
            'webhook_subscribed' => false,
            'last_error' => $reason === 'user' ? null : $reason,
        ])->save();
        $this->audit->log('whatsapp.disconnected', $connection, [], ['reason' => $reason]);
    }

    public function check(WhatsAppConnection $connection): WhatsAppConnection
    {
        if (! $connection->encrypted_credential_reference) {
            return $connection;
        }
        $health = $this->client->health($connection);
        $connection->forceFill([
            'last_health_check_at' => now(),
            'status' => $health->ok ? WhatsAppConnectionStatus::Connected : WhatsAppConnectionStatus::Error,
            'last_error' => $health->ok ? null : 'Sağlık kontrolü başarısız ('.$health->error.')',
        ])->save();

        return $connection;
    }
}
