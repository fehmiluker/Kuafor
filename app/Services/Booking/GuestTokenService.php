<?php

namespace App\Services\Booking;

use App\Models\Appointment;
use App\Models\GuestAccessToken;
use App\Support\Token;

/** Unguessable, expiring, revocable guest access to ONE appointment. Only hashes are stored. */
final class GuestTokenService
{
    public const SCOPE_VIEW = 'view';

    public const SCOPE_CANCEL = 'cancel';

    public const SCOPE_RESPOND = 'respond_proposal';

    public const SCOPE_REVIEW = 'review';

    public function issue(Appointment $appointment, array $scopes = [self::SCOPE_VIEW, self::SCOPE_CANCEL, self::SCOPE_RESPOND, self::SCOPE_REVIEW], ?int $ttlDays = null): string
    {
        $plain = Token::generate(48);
        GuestAccessToken::withoutGlobalScopes()->create([
            'tenant_id' => $appointment->tenant_id,
            'appointment_id' => $appointment->id,
            'token_hash' => Token::hash($plain),
            'scopes' => array_values($scopes),
            'expires_at' => now()->addDays($ttlDays ?? (int) config('booking.guest_token_ttl_days')),
        ]);

        return $plain;
    }

    /** Resolves the token for the given public reference; null if invalid/expired/revoked/mismatched. */
    public function resolve(string $reference, ?string $plain, string $scope): ?GuestAccessToken
    {
        if (! $plain || strlen($plain) < 32) {
            return null;
        }
        $token = GuestAccessToken::withoutGlobalScopes()->with(['appointment' => fn ($q) => $q->withoutGlobalScopes()])
            ->where('token_hash', Token::hash($plain))->first();
        if (! $token || ! $token->isUsable() || ! $token->allows($scope)
            || ! $token->appointment || ! hash_equals($token->appointment->public_reference, $reference)) {
            return null;
        }
        $token->forceFill(['last_used_at' => now()])->save();

        return $token;
    }

    public function revokeAll(Appointment $appointment): void
    {
        GuestAccessToken::withoutGlobalScopes()->where('appointment_id', $appointment->id)->whereNull('revoked_at')->update(['revoked_at' => now()]);
    }
}
