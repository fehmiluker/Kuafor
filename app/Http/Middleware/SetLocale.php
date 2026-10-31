<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class SetLocale
{
    public const SUPPORTED = ['tr', 'en'];

    public function handle(Request $request, Closure $next): Response
    {
        $locale = $request->user()?->locale ?? ($request->hasSession() ? $request->session()->get('locale') : null) ?? config('app.locale');
        app()->setLocale(in_array($locale, self::SUPPORTED, true) ? $locale : 'tr');

        return $next($request);
    }
}
