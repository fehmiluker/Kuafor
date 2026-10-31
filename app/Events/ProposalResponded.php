<?php

namespace App\Events;

use App\Models\AppointmentProposal;
use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class ProposalResponded implements ShouldDispatchAfterCommit
{
    use Dispatchable, SerializesModels;

    public function __construct(public readonly AppointmentProposal $proposal, public readonly bool $accepted) {}
}
