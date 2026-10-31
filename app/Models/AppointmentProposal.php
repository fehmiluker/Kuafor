<?php

namespace App\Models;

use App\Enums\ProposalStatus;
use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AppointmentProposal extends Model
{
    use BelongsToTenant;

    protected $guarded = ['id', 'open_appointment_key'];

    protected $hidden = ['token_hash', 'open_appointment_key'];

    protected function casts(): array
    {
        return [
            'status' => ProposalStatus::class,
            'proposed_start_utc' => 'immutable_datetime',
            'proposed_end_utc' => 'immutable_datetime',
            'proposed_blocked_until_utc' => 'immutable_datetime',
            'expires_at_utc' => 'immutable_datetime',
            'responded_at_utc' => 'immutable_datetime',
        ];
    }

    public function appointment(): BelongsTo
    {
        return $this->belongsTo(Appointment::class);
    }

    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class)->withTrashed();
    }

    public function isOpen(): bool
    {
        return $this->status === ProposalStatus::Open && $this->expires_at_utc->isFuture();
    }
}
