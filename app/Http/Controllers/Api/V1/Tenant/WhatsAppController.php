<?php

namespace App\Http\Controllers\Api\V1\Tenant;

use App\Http\Responses\ApiResponse;
use App\Models\Branch;
use App\Models\WhatsAppConnection;
use App\Services\WhatsApp\ConnectionService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Branch WhatsApp connection management. `connect` receives the WABA id,
 * phone number id and access token produced by Meta Embedded Signup (the
 * frontend runs the FB SDK flow; the code->token exchange belongs on the server).
 */
class WhatsAppController extends TenantApiController
{
    public function index(Request $request): JsonResponse
    {
        $q = $this->scope()->scopeBranches(WhatsAppConnection::query()->with('branch'), $request->user(), $this->tenant());

        return ApiResponse::ok($q->get()->map(fn ($c) => $this->present($c)));
    }

    public function connect(Request $request, Branch $branch, ConnectionService $connections): JsonResponse
    {
        $this->authorize('manageWhatsApp', $branch);
        $data = $request->validate([
            'waba_id' => ['required', 'string', 'regex:/^\d{5,30}$/'],
            'phone_number_id' => ['required', 'string', 'regex:/^\d{5,30}$/'],
            'access_token' => ['required', 'string', 'min:20', 'max:2000'],
            'display_phone' => ['nullable', 'string', 'max:30'],
        ]);
        $c = $connections->connect($branch, $data['waba_id'], $data['phone_number_id'], $data['access_token'], $data['display_phone'] ?? null);

        return ApiResponse::ok($this->present($c));
    }

    /** Embedded Signup: exchange the code server-side and register the number. */
    public function embeddedSignup(Request $request, Branch $branch, ConnectionService $connections): JsonResponse
    {
        $this->authorize('manageWhatsApp', $branch);
        $data = $request->validate([
            'code' => ['required', 'string', 'max:2000'],
            'waba_id' => ['required', 'string', 'regex:/^\d{5,30}$/'],
            'phone_number_id' => ['required', 'string', 'regex:/^\d{5,30}$/'],
        ]);

        return ApiResponse::ok($this->present($connections->connectViaEmbeddedSignup($branch, $data['code'], $data['waba_id'], $data['phone_number_id'])));
    }

    public function disconnect(Branch $branch, ConnectionService $connections): JsonResponse
    {
        $this->authorize('manageWhatsApp', $branch);
        $c = WhatsAppConnection::where('branch_id', $branch->id)->firstOrFail();
        $connections->disconnect($c);

        return ApiResponse::ok($this->present($c->refresh()));
    }

    public function status(Branch $branch, ConnectionService $connections): JsonResponse
    {
        $this->authorize('manageWhatsApp', $branch);
        $c = WhatsAppConnection::where('branch_id', $branch->id)->first();
        $sender = $connections->senderFor($this->tenant(), $branch);
        $payload = $c ? $this->present($connections->check($c)) : ['status' => 'not_connected'];

        return ApiResponse::ok($payload + ['sends_via' => $sender ? ($sender->is_platform ? 'platform' : 'own') : 'none', 'sender_phone' => $sender?->display_phone]);
    }

    /** Never returns the credential. */
    private function present(WhatsAppConnection $c): array
    {
        return [
            'branch_id' => $c->branch_id, 'status' => $c->status->value, 'status_label' => $c->status->label(),
            'display_phone' => $c->display_phone, 'waba_id' => $c->waba_id, 'phone_number_id' => $c->phone_number_id,
            'webhook_subscribed' => $c->webhook_subscribed, 'onboarding_method' => $c->onboarding_method, 'connected_at' => $c->connected_at?->toIso8601ZuluString(),
            'last_health_check_at' => $c->last_health_check_at?->toIso8601ZuluString(), 'last_error' => $c->last_error,
            'mode' => config('whatsapp.mode'),
        ];
    }
}
