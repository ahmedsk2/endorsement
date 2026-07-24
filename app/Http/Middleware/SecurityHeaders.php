<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * First-class security headers on every response (the legacy bootstrap.php hardening,
 * made deployment-independent — no reliance on web-server config being right).
 *
 * The CSP allows 'unsafe-inline' styles because Vue's runtime injects component styles
 * inline in dev, and 'unsafe-eval' is NOT allowed anywhere. In local dev the Vite
 * origin is added so HMR works.
 */
class SecurityHeaders
{
    public function handle(Request $request, Closure $next): Response
    {
        $response = $next($request);

        $devVite = app()->environment('local') ? ' http://localhost:5173 ws://localhost:5173' : '';

        $csp = "default-src 'self'{$devVite}; "
            ."script-src 'self'{$devVite}; "
            ."style-src 'self' 'unsafe-inline'{$devVite}; "
            ."img-src 'self' data:; "
            ."font-src 'self' data:{$devVite}; "
            ."connect-src 'self'{$devVite}; "
            ."frame-ancestors 'none'; "
            ."base-uri 'self'; "
            ."form-action 'self'";

        $response->headers->set('Content-Security-Policy', $csp);
        $response->headers->set('X-Frame-Options', 'DENY');
        $response->headers->set('X-Content-Type-Options', 'nosniff');
        $response->headers->set('Referrer-Policy', 'same-origin');
        $response->headers->set(
            'Permissions-Policy',
            'camera=(), microphone=(), geolocation=(), payment=(), usb=()'
        );

        // HSTS only over TLS — sending it on plain HTTP is meaningless (RFC 6797 §7.2).
        if ($request->isSecure()) {
            $response->headers->set('Strict-Transport-Security', 'max-age=31536000; includeSubDomains');
        }

        return $response;
    }
}
