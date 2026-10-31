<?php

namespace App\Services\Sms;

use App\Support\PhoneNumber;
use Illuminate\Support\Facades\Log;

/** Development/test provider: writes to the log. Configure a real provider (Netgsm, İleti Merkezi...) for production. */
final class LogSmsProvider implements SmsProviderInterface
{
    /** @var list<array{to:string, message:string}> */
    public array $sent = [];

    public function send(string $toE164, string $message): void
    {
        $this->sent[] = ['to' => $toE164, 'message' => $message];
        Log::info('[sms:log] message to '.PhoneNumber::mask($toE164), ['length' => mb_strlen($message)]);
    }
}
