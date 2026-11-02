<?php

namespace App\Http\Controllers\Web;

use App\Http\Controllers\Controller;
use App\Http\Requests\Auth\RegisterRequest;
use App\Models\Plan;
use App\Models\User;
use App\Services\Auth\AccountService;
use Illuminate\Auth\Events\PasswordReset;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Str;
use Illuminate\Validation\Rules\Password as PasswordRule;
use Illuminate\View\View;

class AuthController extends Controller
{
    public function showLogin(): View
    {
        return view('auth.login');
    }

    public function login(Request $request): RedirectResponse
    {
        $data = $request->validate(['email' => ['required', 'email'], 'password' => ['required', 'string'], 'remember' => ['nullable', 'boolean']]);
        if (! Auth::attempt(['email' => strtolower($data['email']), 'password' => $data['password'], 'status' => 'active'], (bool) ($data['remember'] ?? false))) {
            return back()->withInput($request->only('email'))->withErrors(['email' => 'E-posta veya şifre hatalı.']);
        }
        $request->session()->regenerate();
        $user = $request->user();
        $user->forceFill(['last_login_at' => now()])->save();

        if ($user->isPlatformStaff()) {
            return redirect()->route($user->hasTwoFactorEnabled() ? 'two-factor.challenge' : 'two-factor.setup');
        }
        if ($user->activeTenants()->exists()) {
            return redirect()->intended(route('tenant.dashboard'));
        }

        return redirect()->intended(route('account.appointments'));
    }

    public function showRegister(Request $request): View
    {
        return view('auth.register', ['business' => $request->boolean('isletme'), 'plans' => Plan::with('features')->where('active', true)->where('public', true)->orderBy('sort_order')->get()]);
    }

    public function register(RegisterRequest $request, AccountService $accounts): RedirectResponse
    {
        ['user' => $user, 'tenant' => $tenant] = $accounts->register($request->validated());
        Auth::login($user);
        $request->session()->regenerate();
        if ($tenant) {
            $request->session()->put(config('tenancy.session_key'), $tenant->id);

            return redirect()->route('tenant.dashboard')->with('status', 'İşletmeniz oluşturuldu. '.$tenant->subscription?->trial_ends_at?->diffInDays().' günlük deneme süreniz başladı.');
        }

        return redirect()->route('account.profile')->with('status', 'Hesabınız oluşturuldu. Geçmiş randevularınızı görmek için telefonunuzu doğrulayın.');
    }

    public function logout(Request $request): RedirectResponse
    {
        Auth::logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return redirect()->route('home');
    }

    public function showForgot(): View
    {
        return view('auth.forgot');
    }

    public function forgot(Request $request): RedirectResponse
    {
        $data = $request->validate(['email' => ['required', 'email']]);
        Password::sendResetLink(['email' => strtolower($data['email'])]);

        return back()->with('status', 'Bu e-posta kayıtlıysa şifre sıfırlama bağlantısı gönderildi.');
    }

    public function showReset(Request $request, string $token): View
    {
        return view('auth.reset', ['token' => $token, 'email' => $request->query('email')]);
    }

    /** Salon owner invitation link (7-day "invites" broker): same form as a password reset. */
    public function showInvite(Request $request, string $token): View
    {
        return view('auth.reset', ['token' => $token, 'email' => $request->query('email'), 'invite' => true]);
    }

    public function acceptInvite(Request $request): RedirectResponse
    {
        return $this->reset($request, 'invites');
    }

    public function reset(Request $request, ?string $broker = null): RedirectResponse
    {
        $data = $request->validate(['token' => ['required'], 'email' => ['required', 'email'], 'password' => ['required', 'confirmed', PasswordRule::min(10)->letters()->numbers()]]);
        $data['email'] = strtolower($data['email']);
        $status = Password::broker($broker)->reset($data, function (User $user, string $password) {
            $user->forceFill(['password' => Hash::make($password), 'remember_token' => Str::random(60), 'email_verified_at' => $user->email_verified_at ?? now()])->save(); // the e-mailed link proves the address
            $user->tokens()->delete(); // revoke API sessions after a reset
            event(new PasswordReset($user));
        });

        return $status === Password::PASSWORD_RESET
            ? redirect()->route('login')->with('status', 'Şifreniz güncellendi, giriş yapabilirsiniz.')
            : back()->withErrors(['email' => 'Bağlantı geçersiz veya süresi dolmuş.']);
    }
}
