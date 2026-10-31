<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;

class BlockedTime extends Model
{
    use BelongsToTenant;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['starts_at_utc' => 'immutable_datetime', 'ends_at_utc' => 'immutable_datetime'];
    }
}
