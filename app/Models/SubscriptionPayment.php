<?php

namespace App\Models;

use App\Casts\MoneyCast;
use Illuminate\Database\Eloquent\Model;

class SubscriptionPayment extends Model
{
    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'amount' => MoneyCast::class,
            'raw_redacted' => 'array',
            'period_start' => 'immutable_datetime',
            'period_end' => 'immutable_datetime',
            'paid_at' => 'immutable_datetime',
        ];
    }
}
