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
            $h->set('Content-Security-Policy', self::policy());
        }

        return $response;
    }

    /**
     * Default CSP. A page that embeds a payment provider passes its origin
     * (e.g. https://www.paytr.com) so only that page may frame/load it.
     *
     * @param  list<string>  $extraOrigins  allowed for script-src and frame-src
     */
    public static function policy(array $extraOrigins = []): string
    {
        // Livewire/Alpine need 'unsafe-eval'; tighten with nonces + Alpine CSP build if required.
        $vite = app()->environment('local') ? ' http://localhost:5173 ws://localhost:5173' : '';
        $extra = $extraOrigins ? ' '.implode(' ', $extraOrigins) : '';

        return "default-src 'self'; img-src 'self' data: blob: https:; style-src 'self' 'unsafe-inline' https://fonts.bunny.net{$vite}; font-src 'self' data: https://fonts.bunny.net; script-src 'self' 'unsafe-inline' 'unsafe-eval' https://connect.facebook.net{$extra}{$vite}; connect-src 'self' https://*.facebook.com{$vite}; frame-src https://*.facebook.com{$extra}; frame-ancestors 'none'; base-uri 'self'; form-action 'self'";
    }
}
