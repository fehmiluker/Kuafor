<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** One-time, expiring action context for WhatsApp buttons and signed links. */
class ActionToken extends Model
{
    use BelongsToTenant;

    public const SALON_APPROVE = 'salon_approve';

    public const SALON_PROPOSE = 'salon_propose';           // opens slot picker

    public const SALON_PROPOSE_SLOT = 'salon_propose_slot'; // concrete slot in payload

    public const SALON_REJECT = 'salon_reject';

    public const CUSTOMER_ACCEPT = 'customer_accept';

    public const CUSTOMER_REJECT = 'customer_reject';

    public const REVIEW_INVITE = 'review_invite';

    protected $guarded = ['id'];

    protected $hidden = ['token_hash', 'recipient_hash'];

    protected function casts(): array
    {
        return ['payload' => 'array', 'expires_at' => 'immutable_datetime', 'used_at' => 'immutable_datetime'];
    }

    public function appointment(): BelongsTo
    {
        return $this->belongsTo(Appointment::class);
    }

    public function proposal(): BelongsTo
    {
        return $this->belongsTo(AppointmentProposal::class, 'proposal_id');
    }

    public function isUsable(): bool
    {
        return $this->used_at === null && $this->expires_at->isFuture();
    }
}
