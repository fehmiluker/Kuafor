<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Platform-level moderation object (not tenant-scoped: handled by platform staff). */
class ReviewReport extends Model
{
    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['resolved_at' => 'immutable_datetime', 'is_appeal' => 'boolean'];
    }

    public function review(): BelongsTo
    {
        return $this->belongsTo(Review::class)->withoutGlobalScopes();
    }

    public function reporter(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reporter_user_id');
    }
}
