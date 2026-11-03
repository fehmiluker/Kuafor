<?php

return [
    /*
     * Proxies whose X-Forwarded-* headers are trusted (comma-separated IPs/CIDRs, or "*").
     * Read here, not with env() in bootstrap/app.php: once `php artisan optimize` caches the
     * config, env() returns null outside config files and the proxy would silently be ignored
     * (behind Traefik/Cloudflare every URL, cookie and redirect would then be http).
     */
    'proxies' => env('TRUSTED_PROXIES', '127.0.0.1'),
];
