<?php

namespace App\Enums;

/**
 * Operational (transactional) appointment messages are kept separate from
 * marketing: they have different legal bases (see docs/security.md).
 */
enum NotificationCategory: string
{
    use HasValues;

    case Transactional = 'transactional';
    case Reminder = 'reminder';
    case ReviewInvite = 'review_invite';
    case Marketing = 'marketing';
}
