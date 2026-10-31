<?php

namespace App\Http\Controllers\Api\V1\Public;

use App\DTOs\Appointments\Actor;
use App\Enums\AppointmentSource;
use App\Enums\ProposalStatus;
use App\Http\Controllers\Controller;
use App\Http\Requests\Public\StoreAppointmentRequest;
use App\Http\Resources\AppointmentResource;
use App\Http\Responses\ApiResponse;
use App\Models\Appointment;
use App\Models\AppointmentProposal;
use App\Services\Booking\AppointmentService;
use App\Services\Booking\GuestTokenService;
use App\Services\Booking\PublicBookingService;
use App\Services\Catalog\PublicCatalog;
use App\Support\Token;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class BookingController extends Controller
{
    public function store(StoreAppointmentRequest $request, PublicCatalog $catalog, PublicBookingService $booking): JsonResponse
    {
        $branch = $catalog->bookableBranch((int) $request->integer('branch_id')) ?? abort(404);
        $result = $booking->book($branch, $request->validated(), $request->user('sanctum'), AppointmentSource::Api,
            $request->header('Idempotency-Key'), $request->ip());
        $a = AppointmentService::withRelations($result['appointment']);

        return ApiResponse::ok(new AppointmentResource($a), [
            'guest_token' => $result['guest_token'],
            'manage_url' => $result['guest_token'] ? route('guest.appointment', ['reference' => $a->public_reference, 'token' => $result['guest_token']]) : null,
            'online_payment_required' => false,
        ], $result['created'] ? 201 : 200);
    }

    public function show(Request $request, string $reference, GuestTokenService $tokens): JsonResponse
    {
        $token = $tokens->resolve($reference, $this->guestToken($request), GuestTokenService::SCOPE_VIEW) ?? abort(404);
        $a = AppointmentService::withRelations(Appointment::withoutGlobalScopes()->findOrFail($token->appointment_id));
        $a->load(['openProposal' => fn ($q) => $q->withoutGlobalScopes()]);

        return ApiResponse::ok(new AppointmentResource($a));
    }

    public function cancel(Request $request, string $reference, GuestTokenService $tokens, AppointmentService $appointments): JsonResponse
    {
        $data = $request->validate(['reason' => ['nullable', 'string', 'max:500']]);
        $token = $tokens->resolve($reference, $this->guestToken($request), GuestTokenService::SCOPE_CANCEL) ?? abort(404);
        $a = $appointments->cancel(Appointment::withoutGlobalScopes()->findOrFail($token->appointment_id), Actor::guest('guest_api'), $data['reason'] ?? 'Müşteri iptali', enforceCutoff: true);

        return ApiResponse::ok(new AppointmentResource(AppointmentService::withRelations($a)));
    }

    public function acceptProposal(Request $request, int $proposal, AppointmentService $appointments): JsonResponse
    {
        return $this->respond($request, $proposal, true, $appointments);
    }

    public function rejectProposal(Request $request, int $proposal, AppointmentService $appointments): JsonResponse
    {
        return $this->respond($request, $proposal, false, $appointments);
    }

    /** Proposal token is single-purpose: hashed on the proposal row, checked in constant time. */
    private function respond(Request $request, int $proposalId, bool $accept, AppointmentService $appointments): JsonResponse
    {
        $plain = (string) ($request->header('X-Proposal-Token') ?? $request->input('token'));
        $p = AppointmentProposal::withoutGlobalScopes()->find($proposalId);
        if (! $p || strlen($plain) < 32 || ! hash_equals($p->token_hash, Token::hash($plain))) {
            abort(404);
        }
        if ($p->status !== ProposalStatus::Open) {
            return ApiResponse::fail(409, 'proposal_closed', 'Bu teklif artık geçerli değil.');
        }
        $a = $appointments->respondToProposal($p, $accept, Actor::guest('guest_api'));

        return ApiResponse::ok(new AppointmentResource(AppointmentService::withRelations($a)));
    }

    private function guestToken(Request $request): ?string
    {
        return $request->header('X-Guest-Token') ?? $request->query('token');
    }
}
