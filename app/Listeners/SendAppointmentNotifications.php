<?php

namespace App\Listeners;

use App\Events\AppointmentCancelled;
use App\Events\AppointmentCompleted;
use App\Events\AppointmentConfirmed;
use App\Events\AppointmentCreated;
use App\Events\AppointmentExpired;
use App\Events\AppointmentRejected;
use App\Events\AppointmentRescheduled;
use App\Events\ProposalCreated;
use App\Events\ProposalResponded;
use App\Jobs\SendReviewInvite;
use App\Models\Tenant;
use App\Services\Notifications\NotificationDispatcher;
use App\Services\Tenancy\TenantContext;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Events\Dispatcher;

/** Queued after commit; runs inside the appointment's tenant context. */
class SendAppointmentNotifications implements ShouldQueue
{
    public string $queue = 'notifications';

    public bool $afterCommit = true;

    public int $tries = 3;

    public function __construct(private readonly NotificationDispatcher $dispatcher, private readonly TenantContext $context) {}

    public function handleCreated(AppointmentCreated $e): void
    {
        $this->inTenant($e->appointment->tenant_id, fn () => $this->dispatcher->appointmentCreated($e->appointment->fresh(), $e->context['guest_token'] ?? null));
    }

    public function handleConfirmed(AppointmentConfirmed $e): void
    {
        $this->inTenant($e->appointment->tenant_id, fn () => $this->dispatcher->appointmentConfirmed($e->appointment->fresh()));
    }

    public function handleRejected(AppointmentRejected $e): void
    {
        $this->inTenant($e->appointment->tenant_id, fn () => $this->dispatcher->appointmentRejected($e->appointment->fresh(), $e->context['reason'] ?? null));
    }

    public function handleCancelled(AppointmentCancelled $e): void
    {
        $this->inTenant($e->appointment->tenant_id, fn () => $this->dispatcher->appointmentCancelled($e->appointment->fresh(), $e->context['by'] ?? 'user'));
    }

    public function handleRescheduled(AppointmentRescheduled $e): void
    {
        $this->inTenant($e->appointment->tenant_id, fn () => $this->dispatcher->appointmentRescheduled($e->appointment->fresh()));
    }

    public function handleExpired(AppointmentExpired $e): void
    {
        $this->inTenant($e->appointment->tenant_id, fn () => $this->dispatcher->appointmentExpired($e->appointment->fresh()));
    }

    public function handleCompleted(AppointmentCompleted $e): void
    {
        // Invite a couple of hours later so the customer has left the salon.
        SendReviewInvite::dispatch($e->appointment->id)->delay(now()->addHours(2));
    }

    public function handleProposalCreated(ProposalCreated $e): void
    {
        $this->inTenant($e->proposal->tenant_id, fn () => $this->dispatcher->proposalCreated($e->proposal->fresh(), $e->plainToken));
    }

    public function handleProposalResponded(ProposalResponded $e): void
    {
        $this->inTenant($e->proposal->tenant_id, fn () => $this->dispatcher->proposalResponded($e->proposal->fresh(), $e->accepted));
    }

    public function subscribe(Dispatcher $events): array
    {
        return [
            AppointmentCreated::class => 'handleCreated',
            AppointmentConfirmed::class => 'handleConfirmed',
            AppointmentRejected::class => 'handleRejected',
            AppointmentCancelled::class => 'handleCancelled',
            AppointmentRescheduled::class => 'handleRescheduled',
            AppointmentExpired::class => 'handleExpired',
            AppointmentCompleted::class => 'handleCompleted',
            ProposalCreated::class => 'handleProposalCreated',
            ProposalResponded::class => 'handleProposalResponded',
        ];
    }

    private function inTenant(int $tenantId, \Closure $fn): void
    {
        $tenant = Tenant::find($tenantId);
        if ($tenant) {
            $this->context->run($tenant, $fn);
        }
    }
}
