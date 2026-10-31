<?php

namespace App\Services\Auth;

use App\Exceptions\DomainRuleException;
use App\Models\PhoneVerification;
use App\Services\Sms\SmsProviderInterface;
use Illuminate\Support\Facades\RateLimiter;

/**
 * 6-digit OTP. Brute-force protection: max attempts per code, max sends per
 * phone per hour, per-IP limit. Codes are stored as HMAC hashes only.
 */
final class PhoneVerificationService
{
    public function __construct(private readonly SmsProviderInterface $sms) {}

    public function send(string $phoneE164, string $purpose, ?int $userId = null, ?string $ip = null): void
    {
        $phoneKey = 'otp-send:'.sha1($phoneE164);
        $ipKey = 'otp-send-ip:'.sha1((string) $ip);
        if (RateLimiter::tooManyAttempts($phoneKey, (int) config('sms.otp_max_sends_per_hour')) || RateLimiter::tooManyAttempts($ipKey, 20)) {
            throw new DomainRuleException('Çok fazla doğrulama kodu istendi. Lütfen daha sonra tekrar deneyin.', 'otp_rate_limited');
        }
        RateLimiter::hit($phoneKey, 3600);
        RateLimiter::hit($ipKey, 3600);

        $code = (string) random_int(100000, 999999);
        PhoneVerification::where('phone_e164', $phoneE164)->where('purpose', $purpose)->whereNull('verified_at')->delete();
        PhoneVerification::create([
            'phone_e164' => $phoneE164, 'user_id' => $userId, 'purpose' => $purpose,
            'code_hash' => $this->hash($phoneE164, $code),
            'expires_at' => now()->addMinutes((int) config('sms.otp_ttl_minutes')),
            'ip_hash' => $ip ? hash_hmac('sha256', $ip, (string) config('app.key')) : null,
        ]);
        $this->sms->send($phoneE164, "Doğrulama kodunuz: {$code}. Kodu kimseyle paylaşmayın.");
    }

    public function verify(string $phoneE164, string $purpose, string $code): bool
    {
        $row = PhoneVerification::where('phone_e164', $phoneE164)->where('purpose', $purpose)
            ->whereNull('verified_at')->latest('id')->first();
        if (! $row || $row->expires_at->isPast()) {
            return false;
        }
        if ($row->attempts >= (int) config('sms.otp_max_attempts')) {
            throw new DomainRuleException('Çok fazla hatalı deneme. Lütfen yeni kod isteyin.', 'otp_locked');
        }
        $row->increment('attempts');
        if (! hash_equals($row->code_hash, $this->hash($phoneE164, $code))) {
            return false;
        }
        $row->forceFill(['verified_at' => now()])->save();

        return true;
    }

    private function hash(string $phone, string $code): string
    {
        return hash_hmac('sha256', $phone.'|'.$code, (string) config('app.key'));
    }
}
