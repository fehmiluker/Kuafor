<?php

namespace App\Http\Controllers\Api\V1\Auth;

use App\Exceptions\DomainRuleException;
use App\Http\Controllers\Controller;
use App\Http\Requests\Auth\RegisterRequest;
use App\Http\Responses\ApiResponse;
use App\Models\User;
use App\Rules\Phone;
use App\Services\Auth\AccountService;
use App\Services\Auth\CustomerLinker;
use App\Services\Auth\PhoneVerificationService;
use App\Support\PhoneNumber;
use App\Support\Totp;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Password;

class AuthController extends Controller
{
    public function register(RegisterRequest $request, AccountService $accounts): JsonResponse
    {
        ['user' => $user, 'tenant' => $tenant] = $accounts->register($request->validated());
        $token = $user->createToken('api', ['*'], now()->addDays(30))->plainTextToken;

        return ApiResponse::created(['user' => $this->userPayload($user), 'tenant' => $tenant ? ['id' => $tenant->id, 'slug' => $tenant->slug] : null], ['token' => $token]);
    }

    public function login(Request $request): JsonResponse
    {
        $data = $request->validate([
            'email' => ['required', 'email'], 'password' => ['required', 'string'],
            'otp' => ['nullable', 'digits:6'], 'device_name' => ['nullable', 'string', 'max:60'],
        ]);
        $user = User::where('email', strtolower($data['email']))->first();
        // Same response for unknown e-mail and wrong password (no user enumeration).
        if (! $user || ! $user->password || ! Hash::check($data['password'], $user->password) || ! $user->isActive()) {
            return ApiResponse::fail(401, 'invalid_credentials', 'E-posta veya şifre hatalı.');
        }
        $abilities = ['*'];
        if ($user->isPlatformStaff()) {
            if (! $user->hasTwoFactorEnabled()) {
                return ApiResponse::fail(403, 'mfa_setup_required', 'Yönetici hesapları için önce web panelden iki adımlı doğrulamayı etkinleştirin.');
            }
            if (empty($data['otp']) || ! Totp::verify($user->two_factor_secret, $data['otp'])) {
                return ApiResponse::fail(401, 'mfa_required', 'Doğrulama uygulamanızdaki 6 haneli kodu girin.');
            }
            $abilities = ['*', 'platform'];
        }
        $user->forceFill(['last_login_at' => now()])->save();
        $token = $user->createToken($data['device_name'] ?? 'api', $abilities, now()->addDays($user->isPlatformStaff() ? 1 : 30))->plainTextToken;

        return ApiResponse::ok(['user' => $this->userPayload($user)], ['token' => $token]);
    }

    public function logout(Request $request): JsonResponse
    {
        $request->user()->currentAccessToken()?->delete();

        return ApiResponse::ok(['logged_out' => true]);
    }

    /** No `code`: sends an OTP. With `code`: verifies and links past guest bookings. */
    public function verifyPhone(Request $request, PhoneVerificationService $otp, CustomerLinker $linker): JsonResponse
    {
        $data = $request->validate(['code' => ['nullable', 'digits:6']]);
        $user = $request->user();
        if (! $user->phone_e164) {
            throw new DomainRuleException('Hesabınızda telefon numarası yok.', 'no_phone');
        }
        if (empty($data['code'])) {
            $otp->send($user->phone_e164, 'account', $user->id, $request->ip());

            return ApiResponse::ok(['sent' => true, 'phone' => PhoneNumber::mask($user->phone_e164)]);
        }
        if (! $otp->verify($user->phone_e164, 'account', $data['code'])) {
            return ApiResponse::fail(422, 'invalid_code', 'Kod hatalı veya süresi dolmuş.');
        }
        $user->forceFill(['phone_verified_at' => now()])->save();
        $linked = $linker->linkVerifiedPhone($user);

        return ApiResponse::ok(['verified' => true, 'linked_bookings' => $linked]);
    }

    /** Booking OTP for guests when the salon requires phone verification. */
    public function sendBookingCode(Request $request, PhoneVerificationService $otp): JsonResponse
    {
        $data = $request->validate(['phone' => ['required', new Phone]]);
        $otp->send(PhoneNumber::normalize($data['phone']), 'booking', null, $request->ip());

        return ApiResponse::ok(['sent' => true]);
    }

    /** Always 200 — never reveals whether the e-mail exists. */
    public function forgotPassword(Request $request): JsonResponse
    {
        $data = $request->validate(['email' => ['required', 'email']]);
        Password::sendResetLink(['email' => strtolower($data['email'])]);

        return ApiResponse::ok(['message' => 'Bu e-posta kayıtlıysa şifre sıfırlama bağlantısı gönderildi.']);
    }

    private function userPayload(User $user): array
    {
        return [
            'id' => $user->id, 'name' => $user->name, 'email' => $user->email,
            'phone' => $user->phone_e164, 'phone_verified' => $user->phone_verified_at !== null, 'locale' => $user->locale,
            'tenants' => $user->activeTenants()->get(['tenants.id', 'tenants.slug', 'tenants.name'])
                ->map(fn ($t) => ['id' => $t->id, 'slug' => $t->slug, 'name' => $t->name, 'roles' => $user->tenantRoles($t)]),
        ];
    }
}
