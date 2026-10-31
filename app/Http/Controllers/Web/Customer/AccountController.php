<?php

namespace App\Http\Controllers\Web\Customer;

use App\DTOs\Appointments\Actor;
use App\Enums\AppointmentStatus;
use App\Http\Controllers\Controller;
use App\Models\Appointment;
use App\Models\CustomerFavorite;
use App\Models\DataSubjectRequest;
use App\Models\NotificationPreference;
use App\Models\Review;
use App\Models\Tenant;
use App\Services\Auth\CustomerLinker;
use App\Services\Auth\PhoneVerificationService;
use App\Services\Booking\AppointmentService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class AccountController extends Controller
{
    public function __construct(private readonly CustomerLinker $linker) {}

    private function mine(Request $request)
    {
        $u = fn ($q) => $q->withoutGlobalScopes();

        return Appointment::withoutGlobalScopes()->whereIn('customer_id', $this->linker->customerIds($request->user()) ?: [0])
            ->with(['items' => $u, 'branch' => $u, 'employee' => $u, 'review' => $u]);
    }

    public function appointments(Request $request): View
    {
        $upcoming = $this->mine($request)->whereIn('status', AppointmentStatus::active())->where('starts_at_utc', '>=', now()->subHours(2))->orderBy('starts_at_utc')->get();
        $past = $this->mine($request)->where(fn ($w) => $w->whereNotIn('status', AppointmentStatus::active())->orWhere('starts_at_utc', '<', now()->subHours(2)))->latest('starts_at_utc')->paginate(15);
        $tenants = Tenant::whereIn('id', $upcoming->pluck('tenant_id')->merge($past->pluck('tenant_id'))->unique())->get()->keyBy('id');

        return view('customer.appointments', compact('upcoming', 'past', 'tenants'));
    }

    public function cancel(Request $request, int $id, AppointmentService $appointments): RedirectResponse
    {
        $a = $this->mine($request)->findOrFail($id);
        $appointments->cancel($a, Actor::user($request->user(), 'customer'), 'Müşteri iptali', enforceCutoff: true);

        return back()->with('status', 'Randevunuz iptal edildi.');
    }

    public function favorites(Request $request): View
    {
        $tenants = Tenant::whereIn('id', CustomerFavorite::where('user_id', $request->user()->id)->pluck('tenant_id'))
            ->with(['branches' => fn ($q) => $q->where('status', 'active')->with('city', 'district')])->get();

        return view('customer.favorites', compact('tenants'));
    }

    public function toggleFavorite(Request $request, string $slug): RedirectResponse
    {
        $tenant = Tenant::query()->bookable()->where('slug', $slug)->firstOrFail();
        $fav = CustomerFavorite::where('user_id', $request->user()->id)->where('tenant_id', $tenant->id)->first();
        $fav ? $fav->delete() : CustomerFavorite::create(['user_id' => $request->user()->id, 'tenant_id' => $tenant->id]);

        return back()->with('status', $fav ? 'Favorilerden çıkarıldı.' : 'Favorilere eklendi.');
    }

    public function reviews(Request $request): View
    {
        $u = fn ($q) => $q->withoutGlobalScopes();
        $reviews = Review::withoutGlobalScopes()->whereIn('customer_id', $this->linker->customerIds($request->user()) ?: [0])
            ->with(['reply' => $u, 'appointment' => $u])->latest()->paginate(15);
        $tenants = Tenant::whereIn('id', $reviews->pluck('tenant_id'))->get()->keyBy('id');

        return view('customer.reviews', compact('reviews', 'tenants'));
    }

    public function profile(Request $request): View
    {
        $prefs = NotificationPreference::where('user_id', $request->user()->id)->get()->keyBy(fn ($p) => $p->channel.'.'.$p->category);

        return view('customer.profile', ['user' => $request->user(), 'prefs' => $prefs,
            'requests' => DataSubjectRequest::where('user_id', $request->user()->id)->latest()->get()]);
    }

    public function updateProfile(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'min:2', 'max:120'], 'locale' => ['required', 'in:tr,en'],
            'prefs' => ['array'], 'prefs.*' => ['boolean'],
        ]);
        $user = $request->user();
        $user->forceFill(['name' => $data['name'], 'locale' => $data['locale']])->save();
        foreach (['whatsapp', 'email'] as $channel) {
            foreach (['reminder', 'review_invite', 'marketing'] as $category) {
                NotificationPreference::updateOrCreate(
                    ['user_id' => $user->id, 'tenant_customer_id' => null, 'channel' => $channel, 'category' => $category],
                    ['enabled' => (bool) ($data['prefs']["{$channel}.{$category}"] ?? false)],
                );
            }
        }

        return back()->with('status', 'Profiliniz güncellendi.');
    }

    public function verifyPhone(Request $request, PhoneVerificationService $otp): RedirectResponse
    {
        $data = $request->validate(['code' => ['nullable', 'digits:6']]);
        $user = $request->user();
        if (empty($data['code'])) {
            $otp->send($user->phone_e164, 'account', $user->id, $request->ip());

            return back()->with('status', 'Doğrulama kodu SMS ile gönderildi.')->with('otp_sent', true);
        }
        if (! $otp->verify($user->phone_e164, 'account', $data['code'])) {
            return back()->withErrors(['code' => 'Kod hatalı veya süresi dolmuş.'])->with('otp_sent', true);
        }
        $user->forceFill(['phone_verified_at' => now()])->save();
        $linked = $this->linker->linkVerifiedPhone($user);

        return back()->with('status', 'Telefonunuz doğrulandı.'.($linked ? " {$linked} işletmedeki geçmiş randevularınız hesabınıza bağlandı." : ''));
    }

    public function dataRequest(Request $request): RedirectResponse
    {
        $data = $request->validate(['type' => ['required', 'in:export,delete,rectify'], 'note' => ['nullable', 'string', 'max:1000']]);
        DataSubjectRequest::create(['user_id' => $request->user()->id, 'type' => $data['type'], 'note' => $data['note'] ?? null]);

        return back()->with('status', 'Talebiniz alındı. Yasal süre içinde (en geç 30 gün) yanıtlanacaktır.');
    }

    /** KVKK md. 11: the data subject's own data in a portable format. */
    public function export(Request $request): JsonResponse
    {
        $user = $request->user();
        $appointments = $this->mine($request)->get()->map(fn ($a) => [
            'reference' => $a->public_reference, 'salon_tenant_id' => $a->tenant_id, 'branch' => $a->branch?->name,
            'starts_at' => $a->starts_at_utc->toIso8601ZuluString(), 'status' => $a->status->value,
            'services' => $a->items->pluck('service_name_snapshot'), 'total' => $a->total_price->toDecimal(),
        ]);

        return response()->json([
            'account' => ['name' => $user->name, 'email' => $user->email, 'phone' => $user->phone_e164, 'created_at' => $user->created_at?->toIso8601ZuluString()],
            'appointments' => $appointments,
            'favorites' => CustomerFavorite::where('user_id', $user->id)->with('tenant:id,name,slug')->get()->map(fn ($f) => $f->tenant?->slug),
            'generated_at' => now()->toIso8601ZuluString(),
        ], 200, ['Content-Disposition' => 'attachment; filename="verilerim.json"'], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
    }
}
