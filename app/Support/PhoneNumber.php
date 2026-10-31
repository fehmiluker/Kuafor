<?php

namespace App\Support;

use libphonenumber\NumberParseException;
use libphonenumber\PhoneNumberFormat;
use libphonenumber\PhoneNumberUtil;

/** E.164 normalisation. Default region TR, so "0532 123 45 67" -> "+905321234567". */
final class PhoneNumber
{
    public static function normalize(?string $input, string $region = 'TR'): ?string
    {
        if ($input === null || trim($input) === '') {
            return null;
        }
        $util = PhoneNumberUtil::getInstance();
        try {
            $number = $util->parse($input, $region);
        } catch (NumberParseException) {
            return null;
        }
        if (! $util->isValidNumber($number)) {
            return null;
        }

        return $util->format($number, PhoneNumberFormat::E164);
    }

    public static function isValid(?string $input, string $region = 'TR'): bool
    {
        return self::normalize($input, $region) !== null;
    }

    /** "+905321234567" -> "+90 532 *** ** 67" for logs/UI where full number is not needed. */
    public static function mask(?string $e164): string
    {
        if (! $e164) {
            return '';
        }
        $len = strlen($e164);

        return substr($e164, 0, 6).str_repeat('*', max(0, $len - 8)).substr($e164, -2);
    }

    /** WhatsApp wa_id is E.164 without "+". */
    public static function toWaId(string $e164): string
    {
        return ltrim($e164, '+');
    }

    public static function fromWaId(string $waId): ?string
    {
        return self::normalize('+'.ltrim($waId, '+'));
    }

    /** Keyed hash for storing "who may use this token" without storing the number. */
    public static function hash(string $e164): string
    {
        return hash_hmac('sha256', $e164, (string) config('app.key'));
    }
}
