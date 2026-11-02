<?php

namespace App\Services\Notifications;

use App\Enums\AppointmentStatus;
use App\Enums\NotificationCategory;
use App\Enums\NotificationChannel;
use App\Mail\AppointmentNotificationMail;
use App\Models\ActionToken;
use App\Models\Appointment;
use App\Models\AppointmentAddress;
use App\Models\AppointmentProposal;
use App\Models\Branch;
use App\Models\NotificationDelivery;
use App\Models\PanelNotification;
use App\Models\Tenant;
use App\Models\TenantCustomer;
use App\Models\User;
use App\Models\WhatsAppConnection;
use App\Models\WhatsAppMessage;
use App\Services\Billing\EntitlementService;
use App\Services\Booking\ActionTokenService;
use App\Services\Booking\BookingSettings;
use App\Services\Booking\GuestTokenService;
use App\Services\Settings\SettingsRepository;
use App\Services\WhatsApp\ConnectionService;
use App\Services\WhatsApp\TemplateCatalog;
use App\Services\WhatsApp\WhatsAppSender;
use App\Support\LocalTime;
use App\Support\PhoneNumber;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\URL;

/**
 * Decides channels and recipients for appointment events.
 *
 * Customer: WhatsApp (plan feature + usable branch connection + approved template
 * + opt-in) -> e-mail -> nothing (the guest link shown at booking still works).
 * Salon: WhatsApp to the branch's configured manager numbers that DIFFER from the
 * branch's sender number -> e-mail to staff -> always a panel notification.
 */
final class NotificationDispatcher
{
    public function __construct(
        private readonly WhatsAppSender $whatsapp,
        private readonly ConsentService $consents,
        private readonly EntitlementService $entitlements,
        private readonly GuestTokenService $guestTokens,
        private readonly ActionTokenService $actionTokens,
        private readonly SettingsRepository $settings,
    ) {}

    // ---------------------------------------------------------------- events

    public function appointmentCreated(Appointment $a, ?string $guestToken = null): void
    {
        $ctx = $this->context($a);
        if ($a->status === AppointmentStatus::Pending) {
            $this->toCustomer($ctx, 'appointment_received', [$ctx['first_name'], $ctx['salon'], $ctx['when'], $this->manageUrl($a, $guestToken)], 'Randevu talebiniz alındı');
            $this->toSalonNewRequest($ctx);
        } else {
            $this->toSalon($ctx, 'salon_new_booking', [$ctx['branch_name'], $ctx['first_name'], $ctx['services'], $ctx['when']], 'Yeni randevu', 'appointment_created');
        }
    }

    public function appointmentConfirmed(Appointment $a): void
    {
        $ctx = $this->context($a);
        $this->toCustomer($ctx, 'appointment_confirmed', [$ctx['first_name'], $ctx['salon'], $ctx['when'], $this->manageUrl($a)], 'Randevunuz onaylandı',
            dedupe: 'confirmed:'.$a->id.':'.$a->starts_at_utc->getTimestamp());
    }

    public function appointmentRejected(Appointment $a, ?string $reason = null): void
    {
        $ctx = $this->context($a);
        if ($reason === 'customer_declined_proposal') {
            $this->toSalon($ctx, 'salon_update', [$ctx['branch_name'], $ctx['first_name'].' alternatif saati reddetti', $ctx['ref']], 'Alternatif saat reddedildi', 'proposal_rejected');

            return;
        }
        $this->toCustomer($ctx, 'appointment_rejected', [$ctx['first_name'], $ctx['salon'], $ctx['when'], $this->salonUrl($a)], 'Randevu talebiniz onaylanamadı');
    }

    public function appointmentCancelled(Appointment $a, string $by): void
    {
        $ctx = $this->context($a);
        if ($by === 'guest' || $by === 'customer') {
            $this->toSalon($ctx, 'salon_update', [$ctx['branch_name'], $ctx['first_name'].' randevusunu iptal etti ('.$ctx['when'].')', $ctx['ref']], 'Randevu iptal edildi', 'appointment_cancelled');
        } else {
            $this->toCustomer($ctx, 'appointment_cancelled', [$ctx['first_name'], $ctx['salon'], $ctx['when'], $this->salonUrl($a)], 'Randevunuz iptal edildi');
        }
    }

    public function appointmentRescheduled(Appointment $a): void
    {
        $ctx = $this->context($a);
        $this->toCustomer($ctx, 'appointment_rescheduled', [$ctx['first_name'], $ctx['salon'], $ctx['when'], $this->manageUrl($a)], 'Randevunuz güncellendi',
            dedupe: 'rescheduled:'.$a->id.':'.$a->starts_at_utc->getTimestamp());
    }

    public function appointmentExpired(Appointment $a): void
    {
        $ctx = $this->context($a);
        $this->toCustomer($ctx, 'appointment_rejected', [$ctx['first_name'], $ctx['salon'], $ctx['when'], $this->salonUrl($a)], 'Randevu talebinizin süresi doldu');
        $this->panel($ctx, 'appointment_expired', 'Yanıtlanmayan talep süresi doldu', $ctx['first_name'].' — '.$ctx['when']);
    }

    public function proposalCreated(AppointmentProposal $p, ?string $plainProposalToken): void
    {
        $a = Appointment::withoutGlobalScopes()->findOrFail($p->appointment_id);
        $ctx = $this->context($a);
        $newWhen = LocalTime::format($p->proposed_start_utc, $ctx['tz'], 'd F l H:i');
        $until = LocalTime::format($p->expires_at_utc, $ctx['tz'], 'd F H:i');
        $customerPhone = $ctx['customer']->phone_e164;
        $ttl = (int) max(1, now()->diffInMinutes($p->expires_at_utc));
        $via = ['connection_id' => $this->usableConnection($ctx)?->id];
        $accept = 'a:'.$this->actionTokens->issue($a, ActionToken::CUSTOMER_ACCEPT, 'customer', $customerPhone, $via, $p->id, $ttl);
        $reject = 'a:'.$this->actionTokens->issue($a, ActionToken::CUSTOMER_REJECT, 'customer', $customerPhone, $via, $p->id, $ttl);
        $link = $plainProposalToken ? route('proposal.show', ['proposal' => $p->id, 'token' => $plainProposalToken]) : $this->manageUrl($a);

        $sent = $this->toCustomer($ctx, 'proposal_offer', [$ctx['first_name'], $ctx['salon'], $newWhen, $until], 'Size yeni bir saat önerildi',
            buttons: [$accept, $reject], link: $link, linkLabel: 'Teklifi görüntüle');
        if (! $sent) {
            // Buttons unusable -> the e-mail/link flow is the channel; drop button tokens.
            $this->actionTokens->invalidateForAppointment($a->id, [ActionToken::CUSTOMER_ACCEPT, ActionToken::CUSTOMER_REJECT]);
        }
    }

    public function proposalResponded(AppointmentProposal $p, bool $accepted): void
    {
        $a = Appointment::withoutGlobalScopes()->findOrFail($p->appointment_id);
        $ctx = $this->context($a);
        if ($accepted) {
            $this->toSalon($ctx, 'salon_update', [$ctx['branch_name'], $ctx['first_name'].' önerilen saati kabul etti: '.$ctx['when'], $ctx['ref']], 'Alternatif saat kabul edildi', 'proposal_accepted');
        }
    }

    public function reminder(Appointment $a): bool
    {
        $ctx = $this->context($a);

        return $this->toCustomer($ctx, 'appointment_reminder', [$ctx['first_name'], $ctx['salon'], $ctx['when'], $this->manageUrl($a)], 'Randevu hatırlatması',
            category: NotificationCategory::Reminder, dedupe: 'reminder:'.$a->id.':'.$a->starts_at_utc->getTimestamp());
    }

    public function reviewInvite(Appointment $a): void
    {
        if ($a->status !== AppointmentStatus::Completed || ! $this->entitlements->hasFeature($this->tenantOf($a), 'reviews')) {
            return;
        }
        $ctx = $this->context($a);
        $token = $this->guestTokens->issue($a, [GuestTokenService::SCOPE_REVIEW, GuestTokenService::SCOPE_VIEW], 14);
        $url = route('guest.review', ['reference' => $a->public_reference, 'token' => $token]);
        $this->toCustomer($ctx, 'review_invite', [$ctx['first_name'], $ctx['salon'], $url], 'Ziyaretinizi değerlendirin',
            category: NotificationCategory::ReviewInvite, dedupe: 'review_invite:'.$a->id);
    }

    /** A WhatsApp message failed permanently: deliver the same content by e-mail if possible. */
    public function fallbackForFailedWhatsApp(WhatsAppMessage $message): void
    {
        if (! $message->appointment_id || ! $message->event_type) {
            return;
        }
        $a = Appointment::withoutGlobalScopes()->find($message->appointment_id);
        if (! $a) {
            return;
        }
        $ctx = $this->context($a);
        if (str_starts_with($message->event_type, 'salon_')) {
            $this->panel($ctx, 'whatsapp_failed', 'WhatsApp bildirimi gönderilemedi', 'Randevu '.$ctx['ref'].' için WhatsApp bildirimi başarısız; lütfen paneli kontrol edin.');

            return;
        }
        $email = $ctx['customer']->email;
        if ($email) {
            $this->deliverEmail($a, $email, 'Randevu bildirimi', 'Randevunuzla ilgili bir güncelleme var: '.$ctx['salon'].', '.$ctx['when'].'.', $this->manageUrl($a), $message->event_type.'_fallback', 'fallback:'.$message->id);
        }
    }

    // ---------------------------------------------------------------- channels

    private function toCustomer(array $ctx, string $templateKey, array $params, string $subject, array $buttons = [], ?string $link = null, ?string $linkLabel = null, NotificationCategory $category = NotificationCategory::Transactional, ?string $dedupe = null): bool
    {
        /** @var TenantCustomer $customer */
        $customer = $ctx['customer'];
        $a = $ctx['appointment'];
        // Deterministic key: the same event for the same appointment state is delivered once, even if the job is retried.
        $dedupe ??= $templateKey.':'.$a->id.':v'.$a->version;
        $connection = $this->usableConnection($ctx);
        if ($connection && $this->consents->has($customer, $category, NotificationChannel::WhatsApp)) {
            if ($dedupe && ! $this->claimDelivery($a, NotificationChannel::WhatsApp, $customer->phone_e164, $templateKey, $dedupe)) {
                return true;
            }
            $row = $this->whatsapp->queueTemplate($connection, $a, new OutboundMessage($templateKey, $customer->phone_e164, $params, $buttons, $category, $dedupe ? 'wa:'.$dedupe : null));
            if ($row) {
                return true;
            }
        }
        if ($customer->email && $this->consents->preferenceAllows($customer, $category, NotificationChannel::Email)) {
            $body = TemplateCatalog::render($templateKey, $params).($ctx['home'] ? "\n\nHizmet adresinizde verilecek ({$ctx['home']})." : '');
            $this->deliverEmail($a, $customer->email, $subject, $body, $link ?? $this->manageUrl($a), $templateKey, $dedupe ? 'mail:'.$dedupe : null, $linkLabel,
                rebookUrl: route('booking-link', ['slug' => $ctx['tenant']->slug, 'k' => 'eposta']));
        }

        return false;
    }

    private function toSalonNewRequest(array $ctx): void
    {
        $a = $ctx['appointment'];
        $params = [$ctx['branch_name'], $ctx['first_name'], $ctx['services'], $ctx['when']];
        $connection = $this->usableConnection($ctx);
        $sentWhatsApp = false;
        if ($connection) {
            $ttl = BookingSettings::for($ctx['tenant'], $ctx['branch'])->pendingTtlMinutes;
            foreach ($this->managerNumbers($ctx['branch'], $connection) as $phone) {
                $via = ['connection_id' => $connection->id];
                $approve = 'a:'.$this->actionTokens->issue($a, ActionToken::SALON_APPROVE, 'salon', $phone, $via, null, $ttl);
                $propose = 'a:'.$this->actionTokens->issue($a, ActionToken::SALON_PROPOSE, 'salon', $phone, $via, null, $ttl);
                $sentWhatsApp = (bool) $this->whatsapp->queueTemplate($connection, $a, new OutboundMessage('salon_new_request', $phone, $params, [$approve, $propose],
                    dedupeKey: 'wa:salon_new_request:'.$a->id.':v'.$a->version.':'.PhoneNumber::hash($phone))) || $sentWhatsApp;
            }
        }
        // Always: panel notification + signed management link by e-mail (works without WhatsApp,
        // and when the manager's number equals the sender number).
        $this->panel($ctx, 'appointment_requested', 'Yeni randevu talebi', TemplateCatalog::render('salon_new_request', $params), 'salon_new_request:'.$a->id.':v'.$a->version);
        $link = $this->salonActionUrl($a);
        foreach ($this->staffEmails($ctx) as $email) {
            $this->deliverEmail($a, $email, ($ctx['home'] ? '🏠 ' : '').'Yeni randevu talebi', TemplateCatalog::render('salon_new_request', $params).($ctx['home'] ? "\n\nAdrese hizmet: {$ctx['home']} (açık adres panelde)." : ''), $link, 'salon_new_request', 'salon_req:'.$a->id.':'.$email, 'Talebi yönet');
        }
        unset($sentWhatsApp);
    }

    private function toSalon(array $ctx, string $templateKey, array $params, string $title, string $panelEvent): void
    {
        $a = $ctx['appointment'];
        $connection = $this->usableConnection($ctx);
        if ($connection) {
            foreach ($this->managerNumbers($ctx['branch'], $connection) as $phone) {
                $this->whatsapp->queueTemplate($connection, $a, new OutboundMessage($templateKey, $phone, $params,
                    dedupeKey: 'wa:'.$panelEvent.':'.$a->id.':v'.$a->version.':'.PhoneNumber::hash($phone)));
            }
        }
        $this->panel($ctx, $panelEvent, $title, TemplateCatalog::render($templateKey, $params), $panelEvent.':'.$a->id.':v'.$a->version);
    }

    private function panel(array $ctx, string $event, string $title, string $body, ?string $dedupe = null): void
    {
        if ($dedupe && ! $this->claimDelivery($ctx['appointment'], NotificationChannel::Panel, 'panel', $event, $dedupe)) {
            return;
        }
        PanelNotification::withoutGlobalScopes()->create([
            'tenant_id' => $ctx['tenant']->id, 'branch_id' => $ctx['branch']->id, 'appointment_id' => $ctx['appointment']->id,
            'event_type' => $event, 'title' => ($ctx['home'] ? '🏠 ' : '').$title, 'body' => mb_substr($body.($ctx['home'] ? "\nAdrese hizmet: {$ctx['home']}" : ''), 0, 1000),
        ]);
        if (! $dedupe) {
            $this->recordDelivery($ctx['appointment'], NotificationChannel::Panel, 'panel', $event, 'sent');
        }
    }

    private function deliverEmail(Appointment $a, string $email, string $subject, string $body, ?string $url, string $event, ?string $dedupe = null, ?string $label = null, ?string $rebookUrl = null): void
    {
        if ($dedupe && ! $this->claimDelivery($a, NotificationChannel::Email, $email, $event, $dedupe)) {
            return;
        }
        Mail::to($email)->queue(new AppointmentNotificationMail($subject, $body, $url, $label ?? 'Randevuyu görüntüle', $rebookUrl));
        if (! $dedupe) {
            $this->recordDelivery($a, NotificationChannel::Email, $email, $event, 'queued');
        }
    }

    /** Returns false when this exact notification was already sent (dedupe across retries/jobs). */
    private function claimDelivery(Appointment $a, NotificationChannel $channel, string $recipient, string $event, string $dedupe): bool
    {
        try {
            $this->recordDelivery($a, $channel, $recipient, $event, 'queued', $channel->value.':'.$dedupe);

            return true;
        } catch (UniqueConstraintViolationException) {
            return false;
        }
    }

    private function recordDelivery(Appointment $a, NotificationChannel $channel, string $recipient, string $event, string $status, ?string $dedupe = null): void
    {
        NotificationDelivery::create([
            'tenant_id' => $a->tenant_id, 'appointment_id' => $a->id, 'channel' => $channel->value,
            'recipient' => str_contains($recipient, '@') ? preg_replace('/(?<=.).(?=[^@]*@)/u', '*', $recipient) : PhoneNumber::mask($recipient),
            'event_type' => $event, 'status' => $status, 'attempt_count' => 1, 'dedupe_key' => $dedupe,
            'sent_at' => $status === 'sent' ? now() : null,
        ]);
    }

    // ---------------------------------------------------------------- lookups

    /** Branch's own number if connected, otherwise the platform number (hybrid model). */
    private function usableConnection(array $ctx): ?WhatsAppConnection
    {
        return app(ConnectionService::class)->senderFor($ctx['tenant'], $ctx['branch']);
    }

    /**
     * Manager numbers configured for the branch, excluding the branch's own
     * sender number (a WhatsApp number cannot message itself / receive its own
     * interactive buttons). Those managers rely on panel + e-mail links instead.
     *
     * @return list<string>
     */
    public function managerNumbers(Branch $branch, WhatsAppConnection $connection): array
    {
        $numbers = (array) ($this->settings->branch($branch)['whatsapp_manager_numbers'] ?? []);

        return array_values(array_filter(array_unique(array_map(fn ($n) => PhoneNumber::normalize((string) $n), $numbers)),
            fn ($n) => $n !== null && $n !== $connection->display_phone));
    }

    /** @return list<string> */
    private function staffEmails(array $ctx): array
    {
        $tenant = $ctx['tenant'];
        $branchStaff = DB::table('branch_user')->where('branch_id', $ctx['branch']->id)->pluck('user_id');
        $ids = $branchStaff->push($tenant->owner_user_id)->filter()->unique();

        return User::whereIn('id', $ids)->whereNotNull('email')->pluck('email')->unique()->values()->all();
    }

    private function context(Appointment $a): array
    {
        $tenant = $this->tenantOf($a);
        $branch = Branch::withoutGlobalScopes()->withTrashed()->findOrFail($a->branch_id);
        $customer = TenantCustomer::withoutGlobalScopes()->withTrashed()->findOrFail($a->customer_id);
        $items = DB::table('appointment_services')->where('appointment_id', $a->id)->orderBy('sort_order')->pluck('service_name_snapshot');
        $tz = $branch->timezone;

        return [
            'appointment' => $a, 'tenant' => $tenant, 'branch' => $branch, 'customer' => $customer, 'tz' => $tz,
            'first_name' => explode(' ', trim($customer->full_name))[0] ?: 'Müşterimiz',
            'salon' => $tenant->name.($branch->name ? ' ('.$branch->name.')' : ''),
            // Shared platform number: salon-facing messages always name the business, not only the branch.
            'branch_name' => $tenant->name.' – '.$branch->name,
            'when' => LocalTime::format($a->starts_at_utc, $tz, 'd F l H:i'),
            'services' => $items->implode(', '),
            'ref' => $a->public_reference,
            // Home visit: district only in messages; the street address is shown on the panel detail page.
            'home' => $a->location_type === 'address'
                ? AppointmentAddress::withoutGlobalScopes()->where('appointment_id', $a->id)->with('district.city')->first()?->shortLabel() ?? 'Adres'
                : null,
        ];
    }

    private function tenantOf(Appointment $a): Tenant
    {
        return Tenant::findOrFail($a->tenant_id);
    }

    private function manageUrl(Appointment $a, ?string $guestToken = null): string
    {
        $guestToken ??= $this->guestTokens->issue($a);

        return route('guest.appointment', ['reference' => $a->public_reference, 'token' => $guestToken]);
    }

    /** Rejected / cancelled / expired: straight back to booking at the same salon. */
    private function salonUrl(Appointment $a): string
    {
        return route('booking-link', ['slug' => $this->tenantOf($a)->slug]);
    }

    /** Signed, expiring deep link into the panel (login + permission still required). */
    private function salonActionUrl(Appointment $a): string
    {
        return URL::temporarySignedRoute('tenant.appointments.show', now()->addDays(2), ['appointment' => $a->id, 'tenant' => $a->tenant_id]);
    }
}
