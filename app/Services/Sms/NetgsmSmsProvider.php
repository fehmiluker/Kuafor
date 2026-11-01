<?php

namespace App\Services\Sms;

use App\Exceptions\DomainRuleException;
use App\Support\PhoneNumber;
use Illuminate\Http\Client\HttpClientException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Netgsm REST v2 (https://api.netgsm.com.tr, HTTP Basic auth with the API
 * sub-user). Default endpoint /sms/rest/v2/send; with NETGSM_OTP=true the
 * dedicated OTP endpoint /sms/rest/v2/otp is used (needs an OTP package on
 * the Netgsm account, Turkish characters are transliterated).
 *
 * Messages sent here are informational (verification codes), not commercial,
 * so no İYS filter is requested.
 */
final class NetgsmSmsProvider implements SmsProviderInterface
{
    /** /send and /otp result codes (Netgsm REST v2). */
    private const ERRORS = [
        '20' => 'mesaj metni hatalı veya çok uzun',
        '30' => 'geçersiz kullanıcı adı/şifre, API yetkisi yok veya IP kısıtı',
        '40' => 'mesaj başlığı (gönderici adı) tanımlı değil',
        '41' => 'mesaj başlığı geçersiz',
        '50' => 'İYS kontrollü gönderim yetkisi yok / numara kontrolü',
        '51' => 'İYS marka bilgisi yok / numara kontrolü',
        '52' => 'numara kontrolü',
        '60' => 'OTP paketi/kredisi yok',
        '70' => 'hatalı veya eksik parametre',
        '80' => 'gönderim sınırı aşıldı',
        '85' => 'aynı numaraya tekrar gönderim sınırı aşıldı',
        '100' => 'Netgsm sistem hatası',
        '101' => 'Netgsm sistem hatası',
    ];

    public function __construct(private readonly array $config) {}

    public static function fromConfig(): self
    {
        return new self((array) config('sms.netgsm'));
    }

    public function send(string $toE164, string $message): void
    {
        foreach (['usercode', 'password', 'header'] as $key) {
            if (blank($this->config[$key] ?? null)) {
                Log::error("[sms:netgsm] missing config sms.netgsm.{$key}");
                throw $this->userError();
            }
        }
        $otp = ! empty($this->config['otp']);
        $number = self::number($toE164);
        $payload = $otp
            ? ['msgheader' => $this->config['header'], 'msg' => self::ascii($message), 'no' => $number]
            : ['msgheader' => $this->config['header'], 'encoding' => 'TR', 'messages' => [['msg' => $message, 'no' => $number]]];
        $url = rtrim((string) $this->config['base_url'], '/').($otp ? '/sms/rest/v2/otp' : '/sms/rest/v2/send');

        try {
            $response = Http::withBasicAuth((string) $this->config['usercode'], (string) $this->config['password'])
                ->acceptJson()->timeout((int) ($this->config['timeout'] ?? 10))->post($url, $payload);
        } catch (HttpClientException $e) {
            Log::warning('[sms:netgsm] connection failed', ['to' => PhoneNumber::mask($toE164), 'error' => $e->getMessage()]);
            throw $this->userError();
        }

        $code = (string) ($response->json('code') ?? '');
        if ($response->successful() && $code === '00') {
            Log::info('[sms:netgsm] sent', ['to' => PhoneNumber::mask($toE164), 'jobid' => $response->json('jobid')]);

            return;
        }
        Log::warning('[sms:netgsm] rejected', [
            'to' => PhoneNumber::mask($toE164), 'http' => $response->status(), 'code' => $code ?: null,
            'reason' => self::ERRORS[$code] ?? ($response->json('description') ?? mb_substr($response->body(), 0, 200)),
        ]);

        throw $this->userError();
    }

    /** E.164 → Netgsm format: Turkish numbers as 905XXXXXXXXX, others with the 00 prefix. */
    public static function number(string $e164): string
    {
        $digits = ltrim($e164, '+');

        return str_starts_with($digits, '90') ? $digits : '00'.$digits;
    }

    /** OTP endpoint accepts GSM 7-bit text only. */
    public static function ascii(string $text): string
    {
        return strtr($text, ['ç' => 'c', 'Ç' => 'C', 'ğ' => 'g', 'Ğ' => 'G', 'ı' => 'i', 'İ' => 'I', 'ö' => 'o', 'Ö' => 'O', 'ş' => 's', 'Ş' => 'S', 'ü' => 'u', 'Ü' => 'U']);
    }

    private function userError(): DomainRuleException
    {
        return new DomainRuleException('SMS gönderilemedi. Lütfen biraz sonra tekrar deneyin.', 'sms_failed');
    }
}
