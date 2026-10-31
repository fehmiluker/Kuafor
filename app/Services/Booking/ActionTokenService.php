<?php

namespace App\Services\Booking;

use App\Models\ActionToken;
use App\Models\Appointment;
use App\Support\PhoneNumber;
use App\Support\Token;
use Illuminate\Support\Facades\DB;

/**
 * One-time action tokens used as WhatsApp button payloads and signed links.
 * The payload sent to Meta is "a:{token}" — opaque, no PII, no ids.
 */
final class ActionTokenService
{
    public function issue(Appointment $appointment, string $purpose, string $actorType, ?string $allowedRecipientE164 = null, array $payload = [], ?int $proposalId = null, ?int $ttlMinutes = null, ?int $actorId = null): string
    {
        $plain = Token::generate(40);
        ActionToken::withoutGlobalScopes()->create([
            'tenant_id' => $appointment->tenant_id,
            'token_hash' => Token::hash($plain),
            'purpose' => $purpose,
            'appointment_id' => $appointment->id,
            'proposal_id' => $proposalId,
            'actor_type' => $actorType,
            'actor_id' => $actorId,
            'recipient_hash' => $allowedRecipientE164 ? PhoneNumber::hash($allowedRecipientE164) : null,
            'payload' => $payload ?: null,
            'expires_at' => now()->addMinutes($ttlMinutes ?? (int) config('whatsapp.action_token_ttl_minutes')),
        ]);

        return $plain;
    }

    /** Find a usable token without consuming it. */
    public function peek(string $plain, ?array $purposes = null): ?ActionToken
    {
        $token = ActionToken::withoutGlobalScopes()->where('token_hash', Token::hash($plain))->first();
        if (! $token || ! $token->isUsable() || ($purposes && ! in_array($token->purpose, $purposes, true))) {
            return null;
        }

        return $token;
    }

    /**
     * Atomically consume a token (single use even under webhook retries/races).
     * If $senderE164 is given, it must match the recipient the token was issued to.
     */
    public function consume(string $plain, ?array $purposes = null, ?string $senderE164 = null): ?ActionToken
    {
        $token = $this->peek($plain, $purposes);
        if (! $token) {
            return null;
        }
        if ($token->recipient_hash !== null && ($senderE164 === null || ! hash_equals($token->recipient_hash, PhoneNumber::hash($senderE164)))) {
            return null;
        }
        $affected = DB::table('action_tokens')->where('id', $token->id)->whereNull('used_at')->update(['used_at' => now(), 'updated_at' => now()]);

        return $affected === 1 ? $token->refresh() : null;
    }

    /** Invalidate sibling tokens (e.g. once the salon approved, the "propose" button is dead). */
    public function invalidateForAppointment(int $appointmentId, ?array $purposes = null): void
    {
        DB::table('action_tokens')->where('appointment_id', $appointmentId)->whereNull('used_at')
            ->when($purposes, fn ($q) => $q->whereIn('purpose', $purposes))
            ->update(['used_at' => now(), 'updated_at' => now()]);
    }
}
