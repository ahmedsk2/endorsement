<?php

namespace Tests\Feature\Security;

use Tests\TestCase;

/**
 * The reference app delegated security headers to web-server config; this project makes
 * them first-class middleware so every deployment gets them regardless of the server in
 * front. The header set mirrors the legacy bootstrap.php hardening.
 */
class SecurityHeadersTest extends TestCase
{
    public function test_every_response_carries_the_baseline_security_headers(): void
    {
        $response = $this->get('/login');

        $response->assertOk();
        $response->assertHeader('X-Frame-Options', 'DENY');
        $response->assertHeader('X-Content-Type-Options', 'nosniff');
        $response->assertHeader('Referrer-Policy', 'no-referrer');

        $csp = $response->headers->get('Content-Security-Policy');
        $this->assertNotNull($csp, 'Content-Security-Policy header is missing.');
        $this->assertStringContainsString("default-src 'self'", $csp);
        $this->assertStringContainsString("frame-ancestors 'none'", $csp);

        $permissions = $response->headers->get('Permissions-Policy');
        $this->assertNotNull($permissions, 'Permissions-Policy header is missing.');
        $this->assertStringContainsString('camera=()', $permissions);
    }

    public function test_hsts_is_sent_only_on_secure_requests(): void
    {
        // Plain HTTP: no HSTS (sending it over HTTP is meaningless per RFC 6797).
        $this->get('/login')->assertHeaderMissing('Strict-Transport-Security');

        // HTTPS: HSTS present with a >= 1 year max-age.
        $secure = $this->get('https://localhost/login');
        $secure->assertHeader('Strict-Transport-Security');
        $this->assertMatchesRegularExpression(
            '/max-age=(\d{8,})/',
            (string) $secure->headers->get('Strict-Transport-Security'),
        );
    }

    public function test_error_responses_carry_the_headers_too(): void
    {
        $response = $this->get('/definitely-not-a-route');

        $response->assertNotFound();
        $response->assertHeader('X-Frame-Options', 'DENY');
        $response->assertHeader('X-Content-Type-Options', 'nosniff');
    }

    /**
     * The health endpoint is STATELESS — it has no session middleware. A global middleware
     * that calls $request->user() throws there, 500s /up, and the container is marked
     * unhealthy forever while every page still renders fine. Found in a production smoke
     * test; pinned here so it cannot come back.
     */
    public function test_the_health_endpoint_is_200_and_still_carries_headers(): void
    {
        $response = $this->get('/up');

        $response->assertOk();
        $response->assertHeader('X-Frame-Options', 'DENY');
        $response->assertHeader('X-Content-Type-Options', 'nosniff');
    }
}
