<?php

namespace App\DTOs\Appointments;

use App\Enums\ActorType;
use App\Models\User;

/** Who performed a state transition, and through which channel. */
final class Actor
{
    public function __construct(public readonly ActorType $type, public readonly ?int $id, public readonly string $source) {}

    public static function user(User $user, string $source = 'panel'): self
    {
        return new self(ActorType::User, $user->id, $source);
    }

    public static function guest(string $source = 'guest_link'): self
    {
        return new self(ActorType::Guest, null, $source);
    }

    public static function system(string $source = 'scheduler'): self
    {
        return new self(ActorType::System, null, $source);
    }

    public static function whatsapp(?int $userId = null): self
    {
        return new self(ActorType::WhatsApp, $userId, 'whatsapp');
    }
}
