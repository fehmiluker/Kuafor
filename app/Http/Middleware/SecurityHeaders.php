<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class SecurityHeaders
{
    public function handle(Request $request, Closure $next): Response
    {
        $response = $next($request);
        $h = $response->headers;
        $h->set('X-Content-Type-Options', 'nosniff');
        $h->set('X-Frame-Options', 'DENY');
        $h->set('Referrer-Policy', 'strict-origin-when-cross-origin');
        $h->set('Permissions-Policy', 'camera=(), microphone=(), geolocation=(self), payment=()');
        if ($request->isSecure()) {
            $h->set('Strict-Transport-Security', 'max-age=31536000; includeSubDomains');
        }
        if (! $h->has('Content-Security-Policy')) {
            // Livewire/Alpine need 'unsafe-eval'; tighten with nonces + Alpine CSP build if required.
            $vite = app()->environment('local') ? ' http://localhost:5173 ws://localhost:5173' : '';
            $h->set('Content-Security-Policy', "default-src 'self'; img-src 'self' data: blob: https:; style-src 'self' 'unsafe-inline' https://fonts.bunny.net{$vite}; font-src 'self' data: https://fonts.bunny.net; script-src 'self' 'unsafe-inline' 'unsafe-eval'{$vite}; connect-src 'self'{$vite}; frame-ancestors 'none'; base-uri 'self'; form-action 'self'");
        }

        return $response;
    }
}
