<?php

namespace App\Enums;

enum BillingInterval: string
{
    use HasValues;

    case Monthly = 'monthly';
    case Yearly = 'yearly';

    public function label(): string
    {
        return $this === self::Monthly ? 'Aylık' : 'Yıllık';
    }
}
