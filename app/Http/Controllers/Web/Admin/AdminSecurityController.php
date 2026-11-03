<?php

namespace App\Http\Controllers\Web\Admin;

use App\Http\Controllers\Controller;
use App\Services\Audit\AuditLogger;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\Rules\Password;
use Illuminate\View\View;

class AdminSecurityController extends Controller
{
    public function edit(): View
    {
        return view('admin.security');
    }

    public function update(Request $request, AuditLogger $audit): RedirectResponse
    {
        $data = $request->validate([
            'current_password' => ['required', 'string', 'current_password:web'],
            'password' => ['required', 'string', 'max:128', 'confirmed', 'different:current_password', Password::min(12)->mixedCase()->numbers()->symbols()],
        ], [
            'current_password.current_password' => 'Mevcut parolanız hatalı.',
            'password.confirmed' => 'Yeni parola ve tekrarı aynı olmalı.',
            'password.different' => 'Yeni parola mevcut paroladan farklı olmalı.',
        ]);

        $user = $request->user();
        DB::transaction(function () use ($user, $data, $audit) {
            $user->forceFill([
                'password' => $data['password'],
                'remember_token' => Str::random(60),
            ])->save();
            $audit->log('auth.password_changed', $user);
        });

        Auth::guard('web')->logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return redirect()->route('login')->with('status', 'Parolanız değiştirildi. Yeni parolanızla giriş yapın.');
    }
}
