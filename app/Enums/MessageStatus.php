<?php

namespace App\Enums;

enum MessageStatus: string
{
    use HasValues;

    case Queued = 'queued';
    case Sent = 'sent';
    case Delivered = 'delivered';
    case Read = 'read';
    case Failed = 'failed';
    case Received = 'received';
    case Skipped = 'skipped';

    /** Ordinal used so out-of-order status webhooks never move a message backwards. */
    public function rank(): int
    {
        return match ($this) {
            self::Queued => 0,
            self::Sent => 1,
            self::Delivered => 2,
            self::Read => 3,
            self::Failed => 4,
            self::Received, self::Skipped => 5,
        };
    }
}
