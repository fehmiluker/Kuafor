<?php

namespace App\Events;

use App\Models\AppointmentProposal;
use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class ProposalCreated implements ShouldDispatchAfterCommit
{
    use Dispatchable, SerializesModels;

    /** $plainToken lets the customer accept/reject via a signed link; it is never persisted. */
    public function __construct(public readonly AppointmentProposal $proposal, public readonly ?string $plainToken = null) {}
}
