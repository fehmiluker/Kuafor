<?php

namespace App\Models;

use App\Enums\ReviewStatus;
use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

class Review extends Model
{
    use BelongsToTenant, HasFactory;

    protected $guarded = ['id', 'status', 'verified_at'];

    protected function casts(): array
    {
        return [
            'status' => ReviewStatus::class,
            'rating' => 'integer',
            'employee_rating' => 'integer',
            'verified_at' => 'immutable_datetime',
            'edit_locked_at' => 'immutable_datetime',
        ];
    }

    public function appointment(): BelongsTo
    {
        return $this->belongsTo(Appointment::class);
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(TenantCustomer::class, 'customer_id')->withTrashed();
    }

    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class)->withTrashed();
    }

    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class)->withTrashed();
    }

    public function reply(): HasOne
    {
        return $this->hasOne(ReviewReply::class);
    }

    public function reports(): HasMany
    {
        return $this->hasMany(ReviewReport::class);
    }

    /** Public display name: "Ayşe K." — never the phone or full surname. */
    public function authorDisplayName(): string
    {
        $parts = preg_split('/\s+/', trim((string) $this->customer?->full_name)) ?: [];
        $first = $parts[0] ?? 'Müşteri';
        $last = count($parts) > 1 ? mb_substr(end($parts), 0, 1).'.' : '';

        return trim($first.' '.$last);
    }
}
