<?php

namespace App\Support;

use Illuminate\Support\Str;

/** Unguessable tokens. Only the SHA-256 hash is ever persisted. */
final class Token
{
    public static function generate(int $length = 48): string
    {
        return Str::random($length);
    }

    public static function hash(string $plain): string
    {
        return hash('sha256', $plain);
    }

    /** Public appointment reference: no ambiguous chars (0/O, 1/I). */
    public static function reference(int $length = 10): string
    {
        $alphabet = 'ABCDEFGHJKLMNPQRSTUVWXYZ23456789';
        $out = '';
        for ($i = 0; $i < $length; $i++) {
            $out .= $alphabet[random_int(0, strlen($alphabet) - 1)];
        }

        return $out;
    }
}
