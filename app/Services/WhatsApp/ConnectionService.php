<?php

namespace App\Services\WhatsApp;

use App\Enums\WhatsAppConnectionStatus;
use App\Exceptions\DomainRuleException;
use App\Models\Branch;
use App\Models\Tenant;
use App\Models\WhatsAppConnection;
use App\Services\Audit\AuditLogger;
use App\Services\Billing\EntitlementService;
use App\Support\PhoneNumber;

/**
 * WhatsApp number bindings (hybrid model):
 *
 *  - PLATFORM number: one connection owned by the platform (no tenant). Every
 *    salon without its own number sends through it, so salons need no setup.
 *  - BRANCH numbers (optional): connected by the salon itself with Meta
 *    Embedded Signup (one click + Facebook login), or by a platform admin on
 *    the salon's behalf, or manually (advanced).
 *
 * Every path ends in finalize(): live health check -> webhook subscription ->
 * "connected". Credentials are stored encrypted and never returned.
 */
final class ConnectionService
{
    public function __construct(private readonly WhatsAppClientInterface $client, private readonly EntitlementService $entitlements, private readonly AuditLogger $audit) {}

    /** Manual / admin-assisted: credentials are already known. */
    public function connect(Branch $branch, string $wabaId, string $phoneNumberId, string $accessToken, ?string $displayPhone = null, string $method = 'manual'): WhatsAppConnection
    {
        $this->assertBranchMayConnect($branch, $phoneNumberId);
        $connection = $this->upsertBranchConnection($branch, $wabaId, $phoneNumberId, $accessToken, $displayPhone, $method);

        return $this->finalize($connection, 'whatsapp.connected');
    }

    /**
     * Embedded Signup callback: the browser sends the short-lived `code` plus
     * waba_id / phone_number_id reported by Meta's popup. The code is exchanged
     * server-side; the number is registered on the Cloud API with a random PIN.
     */
    public function connectViaEmbeddedSignup(Branch $branch, string $code, string $wabaId, string $phoneNumberId): WhatsAppConnection
    {
        $this->assertBranchMayConnect($branch, $phoneNumberId);
        try {
            $token = $this->client->exchangeCode($code);
        } catch (WhatsAppException) {
            throw new DomainRuleException('Meta bağlantısı tamamlanamadı. Lütfen "WhatsApp\'ı bağla" adımını yeniden deneyin.', 'embedded_signup_failed');
        }
        $connection = $this->upsertBranchConnection($branch, $wabaId, $phoneNumberId, $token, null, 'embedded_signup');
        $pin = (string) random_int(100000, 999999);
        if (! $this->client->registerPhoneNumber($connection, $pin)) {
            $connection->forceFill(['status' => WhatsAppConnectionStatus::Error, 'last_error' => 'Numara Cloud API\'ye kaydedilemedi.'])->save();
            throw new DomainRuleException('Numara WhatsApp Cloud API\'ye kaydedilemedi. Numaranın başka bir WhatsApp uygulamasında kullanılmadığından emin olun.', 'register_failed');
        }
        $connection->forceFill(['encrypted_pin' => $pin])->save();

        return $this->finalize($connection, 'whatsapp.connected_embedded');
    }

    /** Platform-wide default sender (super admin). */
    public function connectPlatform(string $wabaId, string $phoneNumberId, string $accessToken, ?string $displayPhone = null): WhatsAppConnection
    {
        $taken = WhatsAppConnection::withoutGlobalScopes()->where('phone_number_id', $phoneNumberId)->where('is_platform', false)->exists();
        if ($taken) {
            throw new DomainRuleException('Bu numara bir şubeye bağlı; platform numarası olarak kullanılamaz.', 'number_in_use');
        }
        $connection = WhatsAppConnection::platform() ?? new WhatsAppConnection;
        $connection->forceFill([
            'tenant_id' => null, 'branch_id' => null, 'is_platform' => true, 'onboarding_method' => 'platform',
            'waba_id' => $wabaId, 'phone_number_id' => $phoneNumberId,
            'display_phone' => PhoneNumber::normalize($displayPhone),
            'encrypted_credential_reference' => $accessToken,
            'status' => WhatsAppConnectionStatus::Pending,
        ])->save();

        return $this->finalize($connection, 'whatsapp.platform_connected');
    }

    /** Revocation: credential is wiped immediately; message history stays. */
    public function disconnect(WhatsAppConnection $connection, string $reason = 'user'): void
    {
        $connection->forceFill([
            'status' => WhatsAppConnectionStatus::Disconnected,
            'encrypted_credential_reference' => null,
            'encrypted_pin' => null,
            'webhook_subscribed' => false,
            'last_error' => $reason === 'user' ? null : $reason,
        ])->save();
        $this->audit->log('whatsapp.disconnected', $connection, [], ['reason' => $reason, 'platform' => $connection->is_platform], tenantId: $connection->tenant_id);
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

    /**
     * Which number would a notification for this branch go out from?
     * Own connected number > platform number (if enabled) > none.
     */
    public function senderFor(Tenant $tenant, Branch $branch): ?WhatsAppConnection
    {
        if (! $this->entitlements->hasFeature($tenant, 'whatsapp')) {
            return null;
        }
        $own = WhatsAppConnection::withoutGlobalScopes()->where('branch_id', $branch->id)->where('is_platform', false)->first();
        if ($own?->isUsable()) {
            return $own;
        }
        if (! config('whatsapp.platform_fallback')) {
            return null;
        }
        $platform = WhatsAppConnection::platform();

        return $platform?->isUsable() ? $platform : null;
    }

    // ------------------------------------------------------------------ internals

    private function assertBranchMayConnect(Branch $branch, string $phoneNumberId): void
    {
        $tenant = Tenant::findOrFail($branch->tenant_id);
        if (! $this->entitlements->hasFeature($tenant, 'whatsapp')) {
            throw new DomainRuleException('WhatsApp entegrasyonu mevcut paketinizde yer almıyor.', 'feature_unavailable');
        }
        $taken = WhatsAppConnection::withoutGlobalScopes()->where('phone_number_id', $phoneNumberId)
            ->where(fn ($q) => $q->where('is_platform', true)->orWhere('branch_id', '!=', $branch->id))->exists();
        if ($taken) {
            throw new DomainRuleException('Bu WhatsApp numarası başka bir şubeye veya platforma bağlı.', 'number_in_use');
        }
    }

    private function upsertBranchConnection(Branch $branch, string $wabaId, string $phoneNumberId, string $token, ?string $displayPhone, string $method): WhatsAppConnection
    {
        $connection = WhatsAppConnection::withoutGlobalScopes()->firstOrNew(['branch_id' => $branch->id], ['tenant_id' => $branch->tenant_id]);
        $connection->forceFill([
            'tenant_id' => $branch->tenant_id,
            'is_platform' => false,
            'onboarding_method' => $method,
            'waba_id' => $wabaId,
            'phone_number_id' => $phoneNumberId,
            'display_phone' => PhoneNumber::normalize($displayPhone),
            'encrypted_credential_reference' => $token,
            'status' => WhatsAppConnectionStatus::Pending,
        ])->save();

        return $connection;
    }

    private function finalize(WhatsAppConnection $connection, string $auditAction): WhatsAppConnection
    {
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
        $this->audit->log($auditAction, $connection, [], [
            'branch_id' => $connection->branch_id, 'phone_number_id' => $connection->phone_number_id, 'method' => $connection->onboarding_method,
        ], tenantId: $connection->tenant_id);

        return $connection;
    }
}
