<?php

namespace App\Services\Audit;

use App\Models\AuditLog;
use App\Services\Tenancy\TenantContext;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Arr;

final class AuditLogger
{
    /** Keys never written to audit logs. */
    private const REDACT = ['password', 'remember_token', 'two_factor_secret', 'encrypted_credential_reference',
        'token', 'token_hash', 'access_token', 'notes', 'tax_info', 'phone_e164', 'email', 'recipient_e164'];

    public function __construct(private readonly TenantContext $context) {}

    public function log(string $action, ?Model $target = null, array $before = [], array $after = [], ?string $reason = null, array $metadata = [], ?int $tenantId = null): AuditLog
    {
        $user = auth()->user();
        $request = app()->runningInConsole() ? null : request();

        return AuditLog::create([
            'actor_type' => $user ? 'user' : 'system',
            'actor_id' => $user?->id,
            'tenant_id' => $tenantId ?? ($target?->getAttribute('tenant_id') ?? $this->context->id()),
            'action' => $action,
            'target_type' => $target ? class_basename($target) : null,
            'target_id' => $target?->getKey(),
            'before' => self::redact($before) ?: null,
            'after' => self::redact($after) ?: null,
            'reason' => $reason,
            'ip_hash' => $request?->ip() ? hash_hmac('sha256', $request->ip(), (string) config('app.key')) : null,
            'metadata' => $metadata ?: null,
        ]);
    }

    public static function redact(array $data): array
    {
        foreach ($data as $key => $value) {
            if (in_array($key, self::REDACT, true)) {
                $data[$key] = '[redacted]';
            } elseif (is_array($value)) {
                $data[$key] = self::redact($value);
            }
        }

        return Arr::except($data, ['updated_at']);
    }
}
