<?php

namespace App\DTOs\WhatsApp;

final class HealthResult
{
    public function __construct(
        public readonly bool $ok,
        public readonly ?string $displayPhone = null,
        public readonly ?string $qualityRating = null,
        public readonly ?string $error = null,
        public readonly array $raw = [],
    ) {}
}
