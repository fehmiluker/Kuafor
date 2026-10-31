<?php

namespace App\Models;

use App\Enums\ConsentStatus;
use Illuminate\Database\Eloquent\Model;

class CommunicationConsent extends Model
{
    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'status' => ConsentStatus::class,
            'proof_metadata' => 'array',
            'captured_at' => 'immutable_datetime',
            'withdrawn_at' => 'immutable_datetime',
        ];
    }
}
