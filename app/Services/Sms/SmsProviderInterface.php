<?php

namespace App\Services\Sms;

interface SmsProviderInterface
{
    public function send(string $toE164, string $message): void;
}
