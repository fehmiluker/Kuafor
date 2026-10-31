<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;

class ReviewReply extends Model
{
    use BelongsToTenant;

    protected $guarded = ['id'];
}
