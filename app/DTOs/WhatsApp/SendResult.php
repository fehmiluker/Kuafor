<?php

namespace App\DTOs\WhatsApp;

final class SendResult
{
    public function __construct(public readonly string $providerMessageId, public readonly array $raw = []) {}
}
