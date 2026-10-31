<?php

namespace App\Http\Controllers\Web\Public;

use App\DTOs\Appointments\Actor;
use App\Enums\ProposalStatus;
use App\Http\Controllers\Controller;
use App\Models\Appointment;
use App\Models\AppointmentProposal;
use App\Models\Review;
use App\Models\Tenant;
use App\Services\Booking\AppointmentService;
use App\Services\Booking\BookingSettings;
use App\Services\Booking\GuestTokenService;
use App\Services\Reviews\ReviewService;
use App\Support\Token;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

/** Unauthenticated, token-scoped appointment management (noindex, no-store). */
class GuestAppointmentController extends Controller
{
    public function __construct(private readonly GuestTokenService $tokens) {}

    public function show(Request $request, string $reference): View
    {
        $token = $this->tokens->resolve($reference, $request->query('token'), GuestTokenService::SCOPE_VIEW) ?? abort(404);
        $a = $this->load($token->appointment_id);
        $tenant = Tenant::findOrFail($a->tenant_id);

        return view('public.guest-appointment', [
            'a' => $a, 'tenant' => $tenant, 'token' => $request->query('token'),
            'canCancel' => $token->allows(GuestTokenService::SCOPE_CANCEL) && in_array($a->status->value, ['pending', 'confirmed', 'alternative_proposed'], true),
            'cutoff' => BookingSettings::for($tenant, $a->branch)->cancellationCutoffMinutes,
        ]);
    }

    public function cancel(Request $request, string $reference, AppointmentService $appointments): RedirectResponse
    {
        $data = $request->validate(['token' => ['required', 'string'], 'reason' => ['nullable', 'string', 'max:500']]);
        $token = $this->tokens->resolve($reference, $data['token'], GuestTokenService::SCOPE_CANCEL) ?? abort(404);
        $appointments->cancel(Appointment::withoutGlobalScopes()->findOrFail($token->appointment_id), Actor::guest('guest_link'), $data['reason'] ?? 'Müşteri iptali', enforceCutoff: true);

        return redirect()->route('guest.appointment', ['reference' => $reference, 'token' => $data['token']])->with('status', 'Randevunuz iptal edildi.');
    }

    public function proposal(Request $request, int $proposal): View
    {
        $p = $this->proposalFromToken($proposal, (string) $request->query('token'));
        $a = $this->load($p->appointment_id);

        return view('public.proposal', ['p' => $p, 'a' => $a, 'tenant' => Tenant::findOrFail($a->tenant_id), 'token' => $request->query('token')]);
    }

    public function respond(Request $request, int $proposal, string $decision, AppointmentService $appointments): RedirectResponse
    {
        $plain = (string) $request->input('token');
        $p = $this->proposalFromToken($proposal, $plain);
        if ($p->status !== ProposalStatus::Open) {
            return back()->withErrors(['proposal' => 'Bu teklif artık geçerli değil.']);
        }
        $a = $appointments->respondToProposal($p, $decision === 'kabul', Actor::guest('proposal_link'));

        return redirect()->route('proposal.show', ['proposal' => $proposal, 'token' => $plain])
            ->with('status', $decision === 'kabul' ? 'Teşekkürler! Randevunuz onaylandı.' : 'Teklifi reddettiniz.');
    }

    public function reviewForm(Request $request, string $reference): View
    {
        $token = $this->tokens->resolve($reference, $request->query('token'), GuestTokenService::SCOPE_REVIEW) ?? abort(404);
        $a = $this->load($token->appointment_id);

        return view('public.review-form', [
            'a' => $a, 'tenant' => Tenant::findOrFail($a->tenant_id), 'token' => $request->query('token'),
            'existing' => Review::withoutGlobalScopes()->where('appointment_id', $a->id)->first(),
        ]);
    }

    public function storeReview(Request $request, string $reference, ReviewService $reviews): RedirectResponse
    {
        $data = $request->validate([
            'token' => ['required', 'string'], 'rating' => ['required', 'integer', 'between:1,5'],
            'employee_rating' => ['nullable', 'integer', 'between:1,5'], 'body' => ['nullable', 'string', 'max:2000'],
        ]);
        $token = $this->tokens->resolve($reference, $data['token'], GuestTokenService::SCOPE_REVIEW) ?? abort(404);
        $reviews->create(Appointment::withoutGlobalScopes()->findOrFail($token->appointment_id), (int) $data['rating'], isset($data['employee_rating']) ? (int) $data['employee_rating'] : null, $data['body'] ?? null);

        return redirect()->route('guest.review', ['reference' => $reference, 'token' => $data['token']])->with('status', 'Değerlendirmeniz için teşekkürler!');
    }

    private function proposalFromToken(int $id, string $plain): AppointmentProposal
    {
        $p = AppointmentProposal::withoutGlobalScopes()->find($id);
        if (! $p || strlen($plain) < 32 || ! hash_equals($p->token_hash, Token::hash($plain))) {
            abort(404);
        }

        return $p;
    }

    private function load(int $id): Appointment
    {
        $u = fn ($q) => $q->withoutGlobalScopes();

        return Appointment::withoutGlobalScopes()->with(['items' => $u, 'branch' => $u, 'employee' => $u, 'customer' => $u, 'openProposal' => $u])->findOrFail($id);
    }
}
