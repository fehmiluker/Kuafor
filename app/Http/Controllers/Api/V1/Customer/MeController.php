<?php

namespace App\Http\Controllers\Api\V1\Customer;

use App\DTOs\Appointments\Actor;
use App\Enums\AppointmentStatus;
use App\Exceptions\DomainRuleException;
use App\Http\Controllers\Controller;
use App\Http\Resources\AppointmentResource;
use App\Http\Resources\ReviewResource;
use App\Http\Resources\SalonResource;
use App\Http\Responses\ApiResponse;
use App\Models\Appointment;
use App\Models\CustomerFavorite;
use App\Models\DataSubjectRequest;
use App\Models\PanelNotification;
use App\Models\Review;
use App\Models\Tenant;
use App\Services\Auth\CustomerLinker;
use App\Services\Booking\AppointmentService;
use App\Services\Reviews\ReviewService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * Customer self-service. Access is limited to appointments whose tenant CRM
 * card is linked to this account (linking requires phone OTP).
 */
class MeController extends Controller
{
    public function __construct(private readonly CustomerLinker $linker) {}

    public function show(Request $request): JsonResponse
    {
        $u = $request->user();

        return ApiResponse::ok(['id' => $u->id, 'name' => $u->name, 'email' => $u->email, 'phone' => $u->phone_e164,
            'phone_verified' => $u->phone_verified_at !== null, 'locale' => $u->locale]);
    }

    public function update(Request $request): JsonResponse
    {
        $data = $request->validate([
            'name' => ['sometimes', 'string', 'min:2', 'max:120'],
            'locale' => ['sometimes', 'in:tr,en'],
            'notification_preferences' => ['sometimes', 'array'],
            'notification_preferences.*.channel' => ['required', 'in:whatsapp,email'],
            'notification_preferences.*.category' => ['required', 'in:transactional,reminder,review_invite,marketing'],
            'notification_preferences.*.enabled' => ['required', 'boolean'],
        ]);
        $u = $request->user();
        $u->forceFill(collect($data)->only(['name', 'locale'])->all())->save();
        foreach ($data['notification_preferences'] ?? [] as $p) {
            DB::table('notification_preferences')->updateOrInsert(
                ['user_id' => $u->id, 'tenant_customer_id' => null, 'channel' => $p['channel'], 'category' => $p['category']],
                ['enabled' => $p['enabled'], 'updated_at' => now(), 'created_at' => now()],
            );
        }

        return $this->show($request);
    }

    public function appointments(Request $request): JsonResponse
    {
        $data = $request->validate(['scope' => ['nullable', 'in:upcoming,past']]);
        $q = $this->myAppointments($request)->with($this->relations())->orderBy('starts_at_utc', ($data['scope'] ?? 'upcoming') === 'past' ? 'desc' : 'asc');
        if (($data['scope'] ?? 'upcoming') === 'upcoming') {
            $q->where('starts_at_utc', '>=', now()->subHours(2))->whereIn('status', AppointmentStatus::active());
        } else {
            $q->where(fn ($w) => $w->where('starts_at_utc', '<', now())->orWhereNotIn('status', AppointmentStatus::active()));
        }

        return ApiResponse::ok(AppointmentResource::collection($q->paginate(20)));
    }

    public function cancel(Request $request, int $id, AppointmentService $appointments): JsonResponse
    {
        $data = $request->validate(['reason' => ['nullable', 'string', 'max:500']]);
        $a = $this->myAppointments($request)->findOrFail($id);
        $a = $appointments->cancel($a, Actor::user($request->user(), 'customer'), $data['reason'] ?? 'Müşteri iptali', enforceCutoff: true);

        return ApiResponse::ok(new AppointmentResource(AppointmentService::withRelations($a)));
    }

    /** Customers request a change; the salon decides (creates a panel notification). */
    public function rescheduleRequest(Request $request, int $id): JsonResponse
    {
        $data = $request->validate(['preferred_date' => ['required', 'date_format:Y-m-d', 'after_or_equal:today'], 'preferred_time' => ['nullable', 'date_format:H:i'], 'note' => ['nullable', 'string', 'max:500']]);
        $a = $this->myAppointments($request)->findOrFail($id);
        if (! in_array($a->status, [AppointmentStatus::Pending, AppointmentStatus::Confirmed], true)) {
            throw new DomainRuleException('Bu randevu için değişiklik talep edilemez.', 'invalid_state');
        }
        PanelNotification::withoutGlobalScopes()->create([
            'tenant_id' => $a->tenant_id, 'branch_id' => $a->branch_id, 'appointment_id' => $a->id, 'event_type' => 'reschedule_requested',
            'title' => 'Müşteri saat değişikliği istiyor',
            'body' => 'Tercih: '.$data['preferred_date'].' '.($data['preferred_time'] ?? '').' '.strip_tags((string) ($data['note'] ?? '')),
        ]);

        return ApiResponse::ok(['requested' => true]);
    }

    public function favorites(Request $request): JsonResponse
    {
        $tenants = Tenant::whereIn('id', CustomerFavorite::where('user_id', $request->user()->id)->pluck('tenant_id'))->get();

        return ApiResponse::ok(SalonResource::collection($tenants));
    }

    public function addFavorite(Request $request): JsonResponse
    {
        $data = $request->validate(['slug' => ['required', 'string']]);
        $tenant = Tenant::query()->bookable()->where('slug', $data['slug'])->firstOrFail();
        CustomerFavorite::firstOrCreate(['user_id' => $request->user()->id, 'tenant_id' => $tenant->id]);

        return ApiResponse::created(['slug' => $tenant->slug]);
    }

    public function removeFavorite(Request $request, string $slug): JsonResponse
    {
        $tenant = Tenant::where('slug', $slug)->firstOrFail();
        CustomerFavorite::where('user_id', $request->user()->id)->where('tenant_id', $tenant->id)->delete();

        return ApiResponse::ok(['removed' => true]);
    }

    public function storeReview(Request $request, int $id, ReviewService $reviews): JsonResponse
    {
        $data = $request->validate(['rating' => ['required', 'integer', 'between:1,5'], 'employee_rating' => ['nullable', 'integer', 'between:1,5'], 'body' => ['nullable', 'string', 'max:2000']]);
        $a = Appointment::withoutGlobalScopes()->findOrFail($id);
        if (! $reviews->userOwnsAppointment($request->user(), $a)) {
            abort(404); // do not reveal other customers' appointments
        }
        $review = $reviews->create($a, $data['rating'], $data['employee_rating'] ?? null, $data['body'] ?? null);

        return ApiResponse::created(new ReviewResource($review->load(['customer' => fn ($q) => $q->withoutGlobalScopes()])));
    }

    public function reviews(Request $request): JsonResponse
    {
        $reviews = Review::withoutGlobalScopes()->whereIn('customer_id', $this->linker->customerIds($request->user()))
            ->with(['customer' => fn ($q) => $q->withoutGlobalScopes(), 'reply' => fn ($q) => $q->withoutGlobalScopes()])->latest()->paginate(20);

        return ApiResponse::ok(ReviewResource::collection($reviews));
    }

    public function dataRequest(Request $request): JsonResponse
    {
        $data = $request->validate(['type' => ['required', 'in:export,delete,rectify'], 'note' => ['nullable', 'string', 'max:1000']]);
        $r = DataSubjectRequest::create(['user_id' => $request->user()->id, 'type' => $data['type'], 'note' => $data['note'] ?? null]);

        return ApiResponse::created(['id' => $r->id, 'status' => $r->status ?? 'open']);
    }

    private function myAppointments(Request $request)
    {
        return Appointment::withoutGlobalScopes()->whereIn('customer_id', $this->linker->customerIds($request->user()) ?: [0]);
    }

    private function relations(): array
    {
        $u = fn ($q) => $q->withoutGlobalScopes();

        return ['items' => $u, 'branch' => $u, 'employee' => $u];
    }
}
