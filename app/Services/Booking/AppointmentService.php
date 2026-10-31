<?php

namespace App\Services\Booking;

use App\DTOs\Appointments\Actor;
use App\DTOs\Appointments\CreateAppointmentData;
use App\Enums\ActorType;
use App\Enums\AppointmentStatus;
use App\Enums\ApprovalMode;
use App\Enums\HoldStatus;
use App\Enums\NotificationCategory;
use App\Enums\NotificationChannel;
use App\Enums\PaymentMethod;
use App\Enums\ProposalStatus;
use App\Enums\RecordStatus;
use App\Events\AppointmentCancelled;
use App\Events\AppointmentCompleted;
use App\Events\AppointmentConfirmed;
use App\Events\AppointmentCreated;
use App\Events\AppointmentExpired;
use App\Events\AppointmentNoShow;
use App\Events\AppointmentRejected;
use App\Events\AppointmentRescheduled;
use App\Events\ProposalCreated;
use App\Events\ProposalResponded;
use App\Exceptions\BookingConflictException;
use App\Exceptions\DomainRuleException;
use App\Exceptions\InvalidTransitionException;
use App\Models\ActionToken;
use App\Models\Appointment;
use App\Models\AppointmentItem;
use App\Models\AppointmentPayment;
use App\Models\AppointmentProposal;
use App\Models\AppointmentStatusHistory;
use App\Models\BookingHold;
use App\Models\Branch;
use App\Models\Employee;
use App\Models\Tenant;
use App\Models\TenantCustomer;
use App\Models\User;
use App\Services\Billing\EntitlementService;
use App\Services\Notifications\ConsentService;
use App\Support\LocalTime;
use App\Support\Money;
use App\Support\Token;
use Carbon\CarbonImmutable;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;

/**
 * All appointment state changes go through here. Every mutation that affects
 * an employee's time runs inside BookingTransaction and acquires the
 * resource-day locks BEFORE re-validating availability.
 *
 * Lock order (always): resource_day_locks (sorted) -> appointment row.
 */
final class AppointmentService
{
    public function __construct(
        private readonly AvailabilityService $availability,
        private readonly ServiceResolver $resolver,
        private readonly BookingLockService $locks,
        private readonly GuestTokenService $guestTokens,
        private readonly ActionTokenService $actionTokens,
        private readonly EntitlementService $entitlements,
        private readonly ConsentService $consents,
    ) {}

    /**
     * @return array{appointment: Appointment, guest_token: ?string, created: bool}
     */
    public function create(Tenant $tenant, CreateAppointmentData $data): array
    {
        if (! $tenant->isActive() || ! $this->entitlements->isUsable($tenant)) {
            throw new DomainRuleException('Bu işletme şu anda randevu kabul etmiyor.', 'tenant_unavailable');
        }
        // Idempotency: a retried request (double click, network retry) returns the original booking.
        if ($data->idempotencyKey && ($existing = $this->findByIdempotencyKey($tenant, $data->idempotencyKey))) {
            return ['appointment' => $existing, 'guest_token' => null, 'created' => false];
        }

        $branch = Branch::withoutGlobalScopes()->where('tenant_id', $tenant->id)->whereKey($data->branchId)->first();
        if (! $branch || ! $branch->isActive()) {
            throw new DomainRuleException('Şube bulunamadı.', 'invalid_branch');
        }
        $branch->setRelation('tenant', $tenant);
        $settings = BookingSettings::for($tenant, $branch);
        $services = $this->resolver->servicesForBranch($branch, $data->serviceIds);
        $candidates = $this->resolver->qualifiedEmployees($branch, $services->keys()->all(), $data->employeeId);
        if ($candidates->isEmpty()) {
            throw new DomainRuleException('Seçilen hizmetleri yapabilecek uygun personel bulunamadı.', 'invalid_employee');
        }

        $customer = $this->resolveCustomer($tenant, $data);
        if ($customer->status === RecordStatus::Blocked) {
            throw new DomainRuleException('Bu numara ile online randevu alınamıyor. Lütfen salonla iletişime geçin.', 'customer_blocked');
        }
        if (! $data->byStaff) {
            $this->assertPendingLimit($tenant, $customer, $settings);
        }

        $start = $data->startsAtUtc->utc()->startOfMinute();
        $ordered = $this->orderCandidates($branch, $candidates, $start);
        $mode = $data->byStaff ? ApprovalMode::Automatic : $settings->approvalMode;

        $lastReason = 'slot_unavailable';
        foreach ($ordered as $employee) {
            $quote = $this->resolver->quote($branch, $employee, $services);
            // Cheap pre-check outside the lock to skip obviously busy employees.
            if (($reason = $this->availability->checkSlot($branch, $employee, $quote, $start, null, ! $data->byStaff)) !== null) {
                $lastReason = $reason;

                continue;
            }
            try {
                $end = $start->addMinutes($quote->durationMinutes());
                $blockedUntil = $end->addMinutes($quote->bufferMinutes());
                $appointment = $this->locks->withEmployeeLocks($tenant->id, $branch->timezone, [['employee_id' => $employee->id, 'start' => $start, 'end' => $blockedUntil]], function () use ($tenant, $branch, $employee, $quote, $start, $end, $blockedUntil, $customer, $data, $mode, $settings) {

                    if (($reason = $this->availability->checkSlot($branch, $employee, $quote, $start, null, ! $data->byStaff)) !== null) {
                        throw new BookingConflictException(reason: $reason);
                    }

                    $status = $mode === ApprovalMode::Automatic ? AppointmentStatus::Confirmed : AppointmentStatus::Pending;
                    $subtotal = $quote->subtotal();
                    $appointment = new Appointment;
                    $appointment->forceFill([
                        'tenant_id' => $tenant->id,
                        'public_reference' => $this->newReference(),
                        'branch_id' => $branch->id,
                        'customer_id' => $customer->id,
                        'employee_id' => $employee->id,
                        'starts_at_utc' => $start,
                        'ends_at_utc' => $end,
                        'blocked_until_utc' => $blockedUntil,
                        'status' => $status,
                        'approval_mode_snapshot' => $mode,
                        'subtotal' => $subtotal,
                        'discount' => Money::zero(),
                        'total_price' => $subtotal,
                        'currency' => 'TRY',
                        'customer_note' => $data->customerNote,
                        'source' => $data->source,
                        'idempotency_key' => $data->idempotencyKey,
                        'created_by_user_id' => $data->staffUserId ?? $data->userId,
                        'confirmed_at' => $status === AppointmentStatus::Confirmed ? now() : null,
                        'pending_expires_at_utc' => $status === AppointmentStatus::Pending ? now()->utc()->addMinutes($settings->pendingTtlMinutes) : null,
                    ])->save();

                    foreach ($quote->lines as $i => $line) {
                        AppointmentItem::withoutGlobalScopes()->create([
                            'tenant_id' => $tenant->id,
                            'appointment_id' => $appointment->id,
                            'service_id' => $line->serviceId,
                            'service_name_snapshot' => $line->name,
                            'duration_minutes_snapshot' => $line->durationMinutes,
                            'buffer_minutes_snapshot' => $line->bufferMinutes,
                            'unit_price_snapshot' => $line->price,
                            'sort_order' => $i,
                        ]);
                    }

                    if ($status === AppointmentStatus::Pending) {
                        $this->createHold($appointment, $start, $blockedUntil, $appointment->pending_expires_at_utc, null, $data->idempotencyKey ? 'appt:'.$data->idempotencyKey : null);
                    }

                    // Consents are stored in the same transaction, i.e. before any notification event fires.
                    $proof = $data->consentProof + ['appointment' => $appointment->public_reference];
                    if ($data->whatsappOptIn) {
                        $this->consents->grant($customer, NotificationCategory::Transactional, NotificationChannel::WhatsApp, 'booking_form', $proof);
                    }
                    if ($data->marketingConsent) {
                        $this->consents->grant($customer, NotificationCategory::Marketing, NotificationChannel::WhatsApp, 'booking_form', $proof);
                    }

                    $this->history($appointment, null, $status, $data->byStaff && $data->staffUserId
                        ? new Actor(ActorType::User, $data->staffUserId, 'panel')
                        : ($data->userId ? new Actor(ActorType::User, $data->userId, $data->source->value) : Actor::guest($data->source->value)),
                        null, ['mode' => $mode->value]);

                    return $appointment;
                });
            } catch (BookingConflictException $e) {
                // A parallel duplicate (double click) may have taken the slot with our own key.
                if ($data->idempotencyKey && ($existing = $this->findByIdempotencyKey($tenant, $data->idempotencyKey))) {
                    return ['appointment' => $existing, 'guest_token' => null, 'created' => false];
                }
                $lastReason = $e->reason;

                continue;
            } catch (UniqueConstraintViolationException $e) {
                // Concurrent duplicate with the same idempotency key won the race.
                if ($data->idempotencyKey && ($existing = $this->findByIdempotencyKey($tenant, $data->idempotencyKey))) {
                    return ['appointment' => $existing, 'guest_token' => null, 'created' => false];
                }
                throw $e;
            }

            $guestToken = $this->guestTokens->issue($appointment);
            AppointmentCreated::dispatch($appointment, ['guest_token' => $guestToken]);
            if ($appointment->status === AppointmentStatus::Confirmed) {
                AppointmentConfirmed::dispatch($appointment, ['auto' => true]);
            }

            return ['appointment' => self::withRelations($appointment->fresh()), 'guest_token' => $guestToken, 'created' => true];
        }

        throw new BookingConflictException(
            $lastReason === 'too_soon' ? 'Bu saat için en erken randevu süresi geçti.' : 'Seçilen saat artık müsait değil. Lütfen başka bir saat seçin.',
            $lastReason,
        );
    }

    public function approve(Appointment $appointment, Actor $actor): Appointment
    {
        $appointment = $this->mutate($appointment, function (Appointment $a) use ($actor) {
            $this->assertTransition($a, AppointmentStatus::Confirmed, [AppointmentStatus::Pending]);
            $quoteCheck = $this->recheck($a, $a->employee_id, $a->starts_at_utc, $a->blocked_until_utc);
            if ($quoteCheck !== null) {
                throw new BookingConflictException('Bu randevunun saati artık müsait değil; lütfen alternatif saat önerin.', $quoteCheck);
            }
            $this->convertHolds($a);
            $from = $a->status;
            $a->forceFill(['status' => AppointmentStatus::Confirmed, 'confirmed_at' => now(), 'pending_expires_at_utc' => null])->save();
            $this->history($a, $from, AppointmentStatus::Confirmed, $actor);
        }, lockSlot: true);
        $this->actionTokens->invalidateForAppointment($appointment->id, [ActionToken::SALON_APPROVE, ActionToken::SALON_PROPOSE, ActionToken::SALON_PROPOSE_SLOT, ActionToken::SALON_REJECT]);
        AppointmentConfirmed::dispatch($appointment);

        return $appointment;
    }

    public function reject(Appointment $appointment, Actor $actor, ?string $reason = null): Appointment
    {
        $appointment = $this->mutate($appointment, function (Appointment $a) use ($actor, $reason) {
            $this->assertTransition($a, AppointmentStatus::Rejected, [AppointmentStatus::Pending, AppointmentStatus::AlternativeProposed]);
            $this->closeOpenProposal($a, ProposalStatus::Withdrawn);
            $this->releaseHolds($a);
            $from = $a->status;
            $a->forceFill(['status' => AppointmentStatus::Rejected, 'cancellation_reason' => $reason, 'pending_expires_at_utc' => null])->save();
            $this->history($a, $from, AppointmentStatus::Rejected, $actor, $reason);
        });
        $this->actionTokens->invalidateForAppointment($appointment->id);
        AppointmentRejected::dispatch($appointment, ['reason' => $reason]);

        return $appointment;
    }

    /**
     * Salon proposes another time for a pending request. The proposed slot is held
     * for proposal_ttl; the original request's hold is released.
     *
     * @return array{proposal: AppointmentProposal, token: string}
     */
    public function propose(Appointment $appointment, CarbonImmutable $startUtc, ?int $employeeId, Actor $actor, ?string $note = null): array
    {
        $employeeId ??= $appointment->employee_id;
        $branch = $this->branchOf($appointment);
        $employee = $this->resolver->qualifiedEmployees($branch, $this->itemsOf($appointment)->pluck('service_id')->filter()->all(), $employeeId)->first();
        if (! $employee) {
            throw new DomainRuleException('Seçilen personel bu hizmetleri veremiyor.', 'invalid_employee');
        }
        $quote = $this->quoteFor($appointment, $branch, $employee);
        $start = $startUtc->utc()->startOfMinute();
        $end = $start->addMinutes($quote->durationMinutes());
        $blockedUntil = $end->addMinutes($quote->bufferMinutes());
        $settings = BookingSettings::for($appointment->tenant()->first(), $branch);
        $plain = Token::generate(48);

        $proposal = $this->locks->withEmployeeLocks($appointment->tenant_id, $branch->timezone, [['employee_id' => $employee->id, 'start' => $start, 'end' => $blockedUntil]], function () use ($appointment, $branch, $employee, $quote, $start, $end, $blockedUntil, $actor, $note, $settings, $plain) {
            $a = $this->lockRow($appointment);
            $this->assertTransition($a, AppointmentStatus::AlternativeProposed, [AppointmentStatus::Pending, AppointmentStatus::AlternativeProposed]);
            // Only one open proposal: withdraw the previous one (and free its hold) first.
            $this->closeOpenProposal($a, ProposalStatus::Withdrawn);
            $this->releaseHolds($a);

            if (($reason = $this->availability->checkSlot($branch, $employee, $quote, $start, new Exclusions($a->id), false)) !== null) {
                throw new BookingConflictException('Önerilen saat müsait değil.', $reason);
            }
            $expires = now()->utc()->addMinutes($settings->proposalTtlMinutes);
            $proposal = AppointmentProposal::withoutGlobalScopes()->create([
                'tenant_id' => $a->tenant_id,
                'appointment_id' => $a->id,
                'proposed_by_user_id' => $actor->type === ActorType::User || $actor->type === ActorType::WhatsApp ? $actor->id : null,
                'proposed_start_utc' => $start,
                'proposed_end_utc' => $end,
                'proposed_blocked_until_utc' => $blockedUntil,
                'employee_id' => $employee->id,
                'status' => ProposalStatus::Open,
                'expires_at_utc' => $expires,
                'token_hash' => Token::hash($plain),
                'note' => $note,
            ]);
            $this->createHold($a, $start, $blockedUntil, $expires, $proposal->id);
            $from = $a->status;
            $a->forceFill(['status' => AppointmentStatus::AlternativeProposed, 'pending_expires_at_utc' => $expires])->save();
            $this->history($a, $from, AppointmentStatus::AlternativeProposed, $actor, $note, ['proposal_id' => $proposal->id]);

            return $proposal;
        });
        $this->actionTokens->invalidateForAppointment($appointment->id, [ActionToken::SALON_APPROVE, ActionToken::SALON_PROPOSE, ActionToken::SALON_PROPOSE_SLOT, ActionToken::SALON_REJECT]);
        ProposalCreated::dispatch($proposal, $plain);

        return ['proposal' => $proposal, 'token' => $plain];
    }

    /** Customer accepts or rejects an open proposal. */
    public function respondToProposal(AppointmentProposal $proposal, bool $accept, Actor $actor): Appointment
    {
        $appointment = Appointment::withoutGlobalScopes()->findOrFail($proposal->appointment_id);
        $branch = $this->branchOf($appointment);

        $lockIntervals = $accept ? [[
            'employee_id' => $proposal->employee_id, 'start' => $proposal->proposed_start_utc, 'end' => $proposal->proposed_blocked_until_utc,
        ]] : [];
        $result = $this->locks->withEmployeeLocks($appointment->tenant_id, $branch->timezone, $lockIntervals, function () use ($proposal, $accept, $actor, $appointment, $branch) {
            $a = $this->lockRow($appointment);
            $p = AppointmentProposal::withoutGlobalScopes()->whereKey($proposal->id)->lockForUpdate()->firstOrFail();
            if ($p->status !== ProposalStatus::Open) {
                throw new DomainRuleException('Bu teklif artık geçerli değil.', 'proposal_closed');
            }
            if ($p->expires_at_utc->isPast()) {
                throw new DomainRuleException('Teklifin süresi doldu.', 'proposal_expired');
            }
            if ($a->status !== AppointmentStatus::AlternativeProposed) {
                throw new InvalidTransitionException('Randevu teklif aşamasında değil.');
            }
            $holdIds = BookingHold::withoutGlobalScopes()->where('proposal_id', $p->id)->pluck('id')->all();

            if (! $accept) {
                $p->forceFill(['status' => ProposalStatus::Rejected, 'responded_at_utc' => now()])->save();
                $this->releaseHolds($a);
                $a->forceFill(['status' => AppointmentStatus::Rejected, 'cancellation_reason' => 'Müşteri alternatif saati reddetti.', 'pending_expires_at_utc' => null])->save();
                $this->history($a, AppointmentStatus::AlternativeProposed, AppointmentStatus::Rejected, $actor, 'customer_declined_proposal', ['proposal_id' => $p->id]);

                return [$a, $p];
            }

            $employee = Employee::withoutGlobalScopes()->findOrFail($p->employee_id);
            $quote = $this->quoteFor($a, $branch, $employee);
            if (($reason = $this->availability->checkSlot($branch, $employee, $quote, $p->proposed_start_utc, new Exclusions($a->id, $holdIds), false)) !== null) {
                throw new BookingConflictException('Önerilen saat artık müsait değil.', $reason);
            }
            $p->forceFill(['status' => ProposalStatus::Accepted, 'responded_at_utc' => now()])->save();
            $this->convertHolds($a);
            $a->forceFill([
                'employee_id' => $p->employee_id,
                'starts_at_utc' => $p->proposed_start_utc,
                'ends_at_utc' => $p->proposed_end_utc,
                'blocked_until_utc' => $p->proposed_blocked_until_utc,
                'status' => AppointmentStatus::Confirmed,
                'confirmed_at' => now(),
                'pending_expires_at_utc' => null,
            ])->save();
            $this->history($a, AppointmentStatus::AlternativeProposed, AppointmentStatus::Confirmed, $actor, 'customer_accepted_proposal', ['proposal_id' => $p->id]);

            return [$a, $p];
        });
        [$a, $p] = $result;
        $this->actionTokens->invalidateForAppointment($a->id, [ActionToken::CUSTOMER_ACCEPT, ActionToken::CUSTOMER_REJECT]);
        ProposalResponded::dispatch($p, $accept);
        if ($accept) {
            AppointmentConfirmed::dispatch($a, ['via' => 'proposal']);
        } else {
            AppointmentRejected::dispatch($a, ['reason' => 'customer_declined_proposal']);
        }

        return $a;
    }

    /** Move a confirmed appointment. On any failure the original booking is untouched. */
    public function reschedule(Appointment $appointment, CarbonImmutable $startUtc, ?int $employeeId, Actor $actor, bool $byStaff = true): Appointment
    {
        $employeeId ??= $appointment->employee_id;
        $branch = $this->branchOf($appointment);
        $employee = $this->resolver->qualifiedEmployees($branch, $this->itemsOf($appointment)->pluck('service_id')->filter()->all(), $employeeId)->first();
        if (! $employee) {
            throw new DomainRuleException('Seçilen personel bu hizmetleri veremiyor.', 'invalid_employee');
        }
        $quote = $this->quoteFor($appointment, $branch, $employee);
        $start = $startUtc->utc()->startOfMinute();
        $end = $start->addMinutes($quote->durationMinutes());
        $blockedUntil = $end->addMinutes($quote->bufferMinutes());
        $old = ['starts_at_utc' => $appointment->starts_at_utc->toIso8601String(), 'employee_id' => $appointment->employee_id];

        // Lock both the old and the new resource-days (sorted inside the lock service).
        $appointment = $this->locks->withEmployeeLocks($appointment->tenant_id, $branch->timezone, [
            ['employee_id' => $appointment->employee_id, 'start' => $appointment->starts_at_utc, 'end' => $appointment->blocked_until_utc],
            ['employee_id' => $employee->id, 'start' => $start, 'end' => $blockedUntil],
        ], function () use ($appointment, $branch, $employee, $quote, $start, $end, $blockedUntil, $actor, $byStaff, $old) {
            $a = $this->lockRow($appointment);
            $this->assertTransition($a, AppointmentStatus::Confirmed, [AppointmentStatus::Confirmed]);
            if (($reason = $this->availability->checkSlot($branch, $employee, $quote, $start, new Exclusions($a->id), ! $byStaff)) !== null) {
                throw new BookingConflictException('Yeni saat müsait değil.', $reason);
            }
            $a->forceFill([
                'employee_id' => $employee->id,
                'starts_at_utc' => $start,
                'ends_at_utc' => $end,
                'blocked_until_utc' => $blockedUntil,
            ])->save();
            $this->history($a, AppointmentStatus::Confirmed, AppointmentStatus::Confirmed, $actor, 'rescheduled', ['from' => $old]);

            return $a;
        });
        AppointmentRescheduled::dispatch($appointment, ['from' => $old]);

        return $appointment;
    }

    public function cancel(Appointment $appointment, Actor $actor, ?string $reason = null, bool $enforceCutoff = false): Appointment
    {
        if ($enforceCutoff) {
            $settings = BookingSettings::for($appointment->tenant()->first(), $this->branchOf($appointment));
            if ($appointment->status === AppointmentStatus::Confirmed
                && $appointment->starts_at_utc->lt(now()->utc()->addMinutes($settings->cancellationCutoffMinutes))) {
                throw new DomainRuleException('Randevuya '.$settings->cancellationCutoffMinutes.' dakikadan az kaldığı için online iptal yapılamaz. Lütfen salonu arayın.', 'cancellation_cutoff');
            }
        }
        $appointment = $this->mutate($appointment, function (Appointment $a) use ($actor, $reason) {
            $this->assertTransition($a, AppointmentStatus::Cancelled, [AppointmentStatus::Pending, AppointmentStatus::AlternativeProposed, AppointmentStatus::Confirmed]);
            $this->closeOpenProposal($a, ProposalStatus::Withdrawn);
            $this->releaseHolds($a);
            $from = $a->status;
            $a->forceFill(['status' => AppointmentStatus::Cancelled, 'cancelled_at' => now(), 'cancellation_reason' => $reason, 'pending_expires_at_utc' => null])->save();
            $this->history($a, $from, AppointmentStatus::Cancelled, $actor, $reason);
        });
        $this->actionTokens->invalidateForAppointment($appointment->id);
        AppointmentCancelled::dispatch($appointment, ['by' => $actor->type->value, 'reason' => $reason]);

        return $appointment;
    }

    /**
     * Mark completed; optionally record in-salon payment(s) and a discount.
     * Payments are bookkeeping only — no money is collected by the platform.
     *
     * @param  list<array{amount: string, method: string, note?: ?string}>  $payments
     */
    public function complete(Appointment $appointment, Actor $actor, array $payments = [], ?string $discount = null): Appointment
    {
        $appointment = $this->mutate($appointment, function (Appointment $a) use ($actor, $payments, $discount) {
            $this->assertTransition($a, AppointmentStatus::Completed, [AppointmentStatus::Confirmed]);
            if ($a->starts_at_utc->isFuture()) {
                throw new DomainRuleException('Başlamamış bir randevu tamamlandı olarak işaretlenemez.', 'not_started');
            }
            $attrs = ['status' => AppointmentStatus::Completed, 'completed_at' => now()];
            if ($discount !== null && $discount !== '') {
                $d = Money::parse($discount);
                if ($d->isNegative() || $d->greaterThan($a->subtotal)) {
                    throw new DomainRuleException('İndirim tutarı geçersiz.', 'invalid_discount');
                }
                $attrs['discount'] = $d;
                $attrs['total_price'] = $a->subtotal->minus($d);
            }
            $a->forceFill($attrs)->save();
            foreach ($payments as $p) {
                $this->recordPaymentRow($a, $p, $actor);
            }
            $this->history($a, AppointmentStatus::Confirmed, AppointmentStatus::Completed, $actor);
            DB::table('tenant_customers')->where('id', $a->customer_id)->update(['last_visit_at' => $a->starts_at_utc]);
        });
        AppointmentCompleted::dispatch($appointment);

        return $appointment;
    }

    public function recordPayment(Appointment $appointment, array $payment, Actor $actor): AppointmentPayment
    {
        if ($appointment->status !== AppointmentStatus::Completed) {
            throw new DomainRuleException('Tahsilat yalnızca tamamlanan randevular için kaydedilebilir.', 'not_completed');
        }

        return $this->recordPaymentRow($appointment, $payment, $actor);
    }

    public function markNoShow(Appointment $appointment, Actor $actor): Appointment
    {
        $appointment = $this->mutate($appointment, function (Appointment $a) use ($actor) {
            $this->assertTransition($a, AppointmentStatus::NoShow, [AppointmentStatus::Confirmed]);
            if ($a->starts_at_utc->isFuture()) {
                throw new DomainRuleException('Randevu saati gelmeden "gelmedi" işaretlenemez.', 'not_started');
            }
            $a->forceFill(['status' => AppointmentStatus::NoShow])->save();
            $this->history($a, AppointmentStatus::Confirmed, AppointmentStatus::NoShow, $actor);
        });
        AppointmentNoShow::dispatch($appointment);

        return $appointment;
    }

    /**
     * Idempotent expiry sweep: pending requests and proposals past their TTL.
     * Safe to run concurrently/repeatedly — each row is re-checked under its row lock.
     *
     * @return int number of appointments expired
     */
    public function expireStale(): int
    {
        $now = now()->utc();
        // Holds: purely informational once past expires_at (they stop blocking automatically).
        DB::table('booking_holds')->where('status', HoldStatus::Held->value)->where('expires_at_utc', '<=', $now)
            ->update(['status' => HoldStatus::Expired->value, 'updated_at' => $now]);

        $count = 0;
        $ids = DB::table('appointments')->whereIn('status', [AppointmentStatus::Pending->value, AppointmentStatus::AlternativeProposed->value])
            ->whereNotNull('pending_expires_at_utc')->where('pending_expires_at_utc', '<=', $now)->limit(500)->pluck('id');
        foreach ($ids as $id) {
            $expired = DB::transaction(function () use ($id, $now) {
                $a = Appointment::withoutGlobalScopes()->whereKey($id)->lockForUpdate()->first();
                if (! $a || ! in_array($a->status, [AppointmentStatus::Pending, AppointmentStatus::AlternativeProposed], true)
                    || ! $a->pending_expires_at_utc || $a->pending_expires_at_utc->gt($now)) {
                    return null;
                }
                $this->closeOpenProposal($a, ProposalStatus::Expired);
                $this->releaseHolds($a, HoldStatus::Expired);
                $from = $a->status;
                $a->forceFill(['status' => AppointmentStatus::Expired, 'pending_expires_at_utc' => null])->save();
                $this->history($a, $from, AppointmentStatus::Expired, Actor::system(), 'ttl_elapsed');

                return $a;
            });
            if ($expired) {
                $count++;
                $this->actionTokens->invalidateForAppointment($expired->id);
                AppointmentExpired::dispatch($expired);
            }
        }

        return $count;
    }

    /** Eager-load display relations regardless of the current tenant context. */
    public static function withRelations(Appointment $a): Appointment
    {
        $unscoped = fn ($q) => $q->withoutGlobalScopes();

        return $a->load(['items' => $unscoped, 'branch' => $unscoped, 'employee' => $unscoped, 'customer' => $unscoped]);
    }

    // ---------------------------------------------------------------- helpers

    private function mutate(Appointment $appointment, \Closure $fn, bool $lockSlot = false): Appointment
    {
        $intervals = $lockSlot ? [[
            'employee_id' => $appointment->employee_id, 'start' => $appointment->starts_at_utc, 'end' => $appointment->blocked_until_utc,
        ]] : [];

        return $this->locks->withEmployeeLocks($appointment->tenant_id, $this->branchOf($appointment)->timezone, $intervals, function () use ($appointment, $fn) {
            $a = $this->lockRow($appointment);
            $fn($a);

            return $a;
        });
    }

    private function lockRow(Appointment $appointment): Appointment
    {
        $a = Appointment::withoutGlobalScopes()->whereKey($appointment->id)->where('tenant_id', $appointment->tenant_id)->lockForUpdate()->firstOrFail();
        $a->setRelation('items', $this->itemsOf($appointment));

        return $a;
    }

    private function itemsOf(Appointment $a)
    {
        if ($a->relationLoaded('items') && $a->items->isNotEmpty()) {
            return $a->items;
        }

        return AppointmentItem::withoutGlobalScopes()->where('appointment_id', $a->id)->orderBy('sort_order')->get();
    }

    private function recheck(Appointment $a, int $employeeId, CarbonImmutable $start, CarbonImmutable $blockedUntil): ?string
    {
        $branch = $this->branchOf($a);
        $employee = Employee::withoutGlobalScopes()->find($employeeId);
        if (! $employee || ! $employee->isActive()) {
            return 'employee_unavailable';
        }
        $quote = $this->quoteFor($a, $branch, $employee);
        $holdIds = BookingHold::withoutGlobalScopes()->where('appointment_id', $a->id)->pluck('id')->all();

        return $this->availability->checkSlot($branch, $employee, $quote, $start, new Exclusions($a->id, $holdIds), false);
    }

    /** Re-quote from the appointment's snapshot so durations of an existing booking never drift. */
    private function quoteFor(Appointment $a, Branch $branch, Employee $employee): Quote
    {
        $items = $this->itemsOf($a);
        $lines = $items->map(fn (AppointmentItem $i) => new ServiceLine((int) $i->service_id, $i->service_name_snapshot, $i->duration_minutes_snapshot, $i->buffer_minutes_snapshot, $i->unit_price_snapshot))->all();

        return new Quote($employee->id, $lines, (int) $employee->buffer_minutes);
    }

    private function branchOf(Appointment $a): Branch
    {
        $branch = Branch::withoutGlobalScopes()->with('tenant')->findOrFail($a->branch_id);

        return $branch;
    }

    private function assertTransition(Appointment $a, AppointmentStatus $to, array $allowedFrom): void
    {
        if (! in_array($a->status, $allowedFrom, true) || ! $a->status->canTransitionTo($to)) {
            throw new InvalidTransitionException("Randevu durumu '{$a->status->label()}' iken bu işlem yapılamaz.");
        }
    }

    private function history(Appointment $a, ?AppointmentStatus $from, AppointmentStatus $to, Actor $actor, ?string $reason = null, array $meta = []): void
    {
        AppointmentStatusHistory::withoutGlobalScopes()->create([
            'tenant_id' => $a->tenant_id,
            'appointment_id' => $a->id,
            'from_status' => $from?->value,
            'to_status' => $to->value,
            'actor_type' => $actor->type->value,
            'actor_id' => $actor->id,
            'source' => $actor->source,
            'reason' => $reason,
            'metadata' => $meta ?: null,
        ]);
        DB::table('appointments')->where('id', $a->id)->increment('version');
    }

    private function createHold(Appointment $a, CarbonImmutable $start, CarbonImmutable $end, CarbonImmutable $expires, ?int $proposalId = null, ?string $key = null): BookingHold
    {
        return BookingHold::withoutGlobalScopes()->create([
            'tenant_id' => $a->tenant_id,
            'branch_id' => $a->branch_id,
            'employee_id' => $proposalId ? AppointmentProposal::withoutGlobalScopes()->whereKey($proposalId)->value('employee_id') : $a->employee_id,
            'appointment_id' => $a->id,
            'proposal_id' => $proposalId,
            'starts_at_utc' => $start,
            'ends_at_utc' => $end,
            'expires_at_utc' => $expires,
            'status' => HoldStatus::Held,
            'idempotency_key' => $key,
        ]);
    }

    private function releaseHolds(Appointment $a, HoldStatus $to = HoldStatus::Released): void
    {
        DB::table('booking_holds')->where('appointment_id', $a->id)->where('status', HoldStatus::Held->value)
            ->update(['status' => $to->value, 'updated_at' => now()]);
    }

    private function convertHolds(Appointment $a): void
    {
        DB::table('booking_holds')->where('appointment_id', $a->id)->where('status', HoldStatus::Held->value)
            ->update(['status' => HoldStatus::Converted->value, 'updated_at' => now()]);
    }

    private function closeOpenProposal(Appointment $a, ProposalStatus $to): void
    {
        DB::table('appointment_proposals')->where('appointment_id', $a->id)->where('status', ProposalStatus::Open->value)
            ->update(['status' => $to->value, 'responded_at_utc' => now(), 'updated_at' => now()]);
    }

    private function recordPaymentRow(Appointment $a, array $p, Actor $actor): AppointmentPayment
    {
        $amount = Money::parse((string) $p['amount']);
        if ($amount->isNegative() || $amount->isZero()) {
            throw new DomainRuleException('Tahsilat tutarı sıfırdan büyük olmalıdır.', 'invalid_amount');
        }
        $method = PaymentMethod::tryFrom((string) $p['method']) ?? throw new DomainRuleException('Geçersiz ödeme yöntemi.', 'invalid_method');

        return AppointmentPayment::withoutGlobalScopes()->create([
            'tenant_id' => $a->tenant_id,
            'appointment_id' => $a->id,
            'amount' => $amount,
            'method' => $method,
            'recorded_by' => $actor->id,
            'paid_at' => now(),
            'note' => $p['note'] ?? null,
        ]);
    }

    private function resolveCustomer(Tenant $tenant, CreateAppointmentData $data): TenantCustomer
    {
        $find = fn () => TenantCustomer::withoutGlobalScopes()->withTrashed()->where('tenant_id', $tenant->id)->where('phone_e164', $data->customerPhoneE164)->first();
        $customer = $find();
        if (! $customer) {
            try {
                $customer = TenantCustomer::withoutGlobalScopes()->create([
                    'tenant_id' => $tenant->id,
                    'user_id' => $data->userId,
                    'full_name' => $data->customerName,
                    'phone_e164' => $data->customerPhoneE164,
                    'email' => $data->customerEmail,
                    'marketing_consent_at' => $data->marketingConsent ? now() : null,
                ]);
            } catch (UniqueConstraintViolationException) {
                $customer = $find(); // concurrent first booking with the same phone
            }
        }
        if ($customer->trashed()) {
            $customer->restore();
        }
        // Link the CRM card to the account only for verified owners of the number (see CustomerLinker).
        if ($data->userId && ! $customer->user_id) {
            $user = User::find($data->userId);
            if ($user && $user->phone_verified_at && $user->phone_e164 === $customer->phone_e164) {
                $customer->forceFill(['user_id' => $user->id])->save();
            }
        }

        return $customer;
    }

    private function assertPendingLimit(Tenant $tenant, TenantCustomer $customer, BookingSettings $settings): void
    {
        $open = Appointment::withoutGlobalScopes()->where('tenant_id', $tenant->id)->where('customer_id', $customer->id)
            ->whereIn('status', [AppointmentStatus::Pending->value, AppointmentStatus::AlternativeProposed->value])->count();
        if ($open >= $settings->maxPendingPerPhone) {
            throw new DomainRuleException('Bu numara ile onay bekleyen çok sayıda talebiniz var. Lütfen mevcut taleplerin sonuçlanmasını bekleyin.', 'too_many_pending');
        }
    }

    /**
     * Deterministic "fark etmez" ordering: fewest active appointments that local
     * day (balanced distribution), then lowest employee id.
     */
    private function orderCandidates(Branch $branch, $candidates, CarbonImmutable $start)
    {
        if ($candidates->count() <= 1) {
            return $candidates->values();
        }
        $localDate = LocalTime::toLocal($start, $branch->timezone)->toDateString();
        [$from, $to] = LocalTime::dayBoundsUtc($localDate, $branch->timezone);
        $load = DB::table('appointments')->whereIn('employee_id', $candidates->pluck('id'))
            ->whereIn('status', AppointmentStatus::active())
            ->where('starts_at_utc', '>=', $from)->where('starts_at_utc', '<', $to)
            ->groupBy('employee_id')->selectRaw('employee_id, COUNT(*) c')->pluck('c', 'employee_id');

        return $candidates->sortBy(fn ($e) => [(int) ($load[$e->id] ?? 0), $e->id])->values();
    }

    private function findByIdempotencyKey(Tenant $tenant, string $key): ?Appointment
    {
        return Appointment::withoutGlobalScopes()->where('tenant_id', $tenant->id)->where('idempotency_key', $key)->first();
    }

    private function newReference(): string
    {
        do {
            $ref = Token::reference(10);
        } while (DB::table('appointments')->where('public_reference', $ref)->exists());

        return $ref;
    }
}
