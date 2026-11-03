<?php

namespace Tests\Feature;

use Tests\TestCase;

/** Behind Traefik/Cloudflare the app must honour X-Forwarded-Proto from trusted proxies, also with cached config. */
class TrustedProxyTest extends TestCase
{
    public function test_forwarded_https_from_a_trusted_proxy_produces_https_asset_urls(): void
    {
        config(['trustedproxy.proxies' => '127.0.0.1,172.16.0.0/12']);

        $html = $this->withServerVariables(['REMOTE_ADDR' => '172.18.0.2', 'HTTP_X_FORWARDED_PROTO' => 'https', 'HTTP_X_FORWARDED_PORT' => '443', 'HTTP_X_FORWARDED_HOST' => 'paradise.com.tr'])
            ->get('/')->assertOk()->getContent();

        $this->assertMatchesRegularExpression('#https://paradise\.com\.tr/build/assets/app-[^"]+\.css#', $html);
        $this->assertDoesNotMatchRegularExpression('#http://paradise\.com\.tr/build/#', $html);
    }

    public function test_forwarded_headers_from_an_untrusted_address_are_ignored(): void
    {
        config(['trustedproxy.proxies' => '127.0.0.1']);

        $html = $this->withServerVariables(['REMOTE_ADDR' => '203.0.113.9', 'HTTP_X_FORWARDED_HOST' => 'evil.example', 'HTTP_X_FORWARDED_PROTO' => 'https'])
            ->get('/')->assertOk()->getContent();

        $this->assertStringNotContainsString('evil.example', $html);
    }
}
