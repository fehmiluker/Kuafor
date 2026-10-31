<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class PlatformSetting extends Model
{
    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['is_encrypted' => 'boolean'];
    }
}
