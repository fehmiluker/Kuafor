<?php

namespace App\Casts;

use App\Support\Money;
use Illuminate\Contracts\Database\Eloquent\CastsAttributes;
use Illuminate\Database\Eloquent\Model;

/** Casts a DECIMAL(12,2) column to Money using the model's `currency` attribute. */
class MoneyCast implements CastsAttributes
{
    public function get(Model $model, string $key, mixed $value, array $attributes): ?Money
    {
        if ($value === null) {
            return null;
        }

        return Money::parse((string) $value, $attributes['currency'] ?? 'TRY');
    }

    public function set(Model $model, string $key, mixed $value, array $attributes): ?string
    {
        if ($value === null) {
            return null;
        }
        if ($value instanceof Money) {
            return $value->toDecimal();
        }
        if (is_float($value)) {
            throw new \InvalidArgumentException('Para tutarları float olarak atanamaz.');
        }

        return Money::parse($value)->toDecimal();
    }
}
