<?php

namespace App\Http\Controllers\Web;

use App\Http\Controllers\Controller;
use App\Services\Audit\AuditLogger;
use App\Support\Totp;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class TwoFactorController extends Controller
{
    public function setup(Request $request): View|RedirectResponse
    {
        $user = $request->user();
        if ($user->hasTwoFactorEnabled()) {
            return redirect()->route('two-factor.challenge');
        }
        $secret = $request->session()->get('2fa_pending_secret') ?? Totp::generateSecret();
        $request->session()->put('2fa_pending_secret', $secret);

        return view('auth.two-factor-setup', ['secret' => $secret, 'uri' => Totp::uri($secret, (string) $user->email, config('app.name'))]);
    }

    public function confirm(Request $request, AuditLogger $audit): RedirectResponse
    {
        $data = $request->validate(['code' => ['required', 'digits:6']]);
        $secret = $request->session()->get('2fa_pending_secret');
        if (! $secret || ! Totp::verify($secret, $data['code'])) {
            return back()->withErrors(['code' => 'Kod doğrulanamadı.']);
        }
        $request->user()->forceFill(['two_factor_secret' => $secret, 'two_factor_confirmed_at' => now()])->save();
        $request->session()->forget('2fa_pending_secret');
        $request->session()->put('two_factor_passed', true);
        $request->session()->regenerate();
        $audit->log('auth.two_factor_enabled', $request->user());

        return redirect()->route('admin.dashboard');
    }

    public function challenge(Request $request): View|RedirectResponse
    {
        return $request->user()->hasTwoFactorEnabled() ? view('auth.two-factor-challenge') : redirect()->route('two-factor.setup');
    }

    public function verify(Request $request): RedirectResponse
    {
        $data = $request->validate(['code' => ['required', 'digits:6']]);
        $user = $request->user();
        if (! $user->hasTwoFactorEnabled() || ! Totp::verify($user->two_factor_secret, $data['code'])) {
            return back()->withErrors(['code' => 'Kod hatalı.']);
        }
        $request->session()->put('two_factor_passed', true);
        $request->session()->regenerate();

        return redirect()->intended(route('admin.dashboard'));
    }
}
