<?php

namespace App\Enums;

trait HasValues
{
    /** @return list<string> */
    public static function values(): array
    {
        return array_map(fn (self $c) => $c->value, self::cases());
    }
}
