<?php

namespace App\Services\Notifications;

use App\Enums\NotificationCategory;

final class OutboundMessage
{
    /**
     * @param  list<string>  $params  template body params (in order)
     * @param  list<string>  $buttonPayloads  quick-reply payloads (a:{token})
     */
    public function __construct(
        public readonly string $templateKey,
        public readonly string $recipientE164,
        public readonly array $params,
        public readonly array $buttonPayloads = [],
        public readonly NotificationCategory $category = NotificationCategory::Transactional,
        public readonly ?string $dedupeKey = null,
    ) {}
}
