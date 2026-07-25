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
            ."object-src 'none'; "
            ."frame-src 'none'; "
            ."base-uri 'self'; "
            ."form-action 'self'";

        $response->headers->set('Content-Security-Policy', $csp);
        $response->headers->set('X-Frame-Options', 'DENY');
        $response->headers->set('X-Content-Type-Options', 'nosniff');
        // no-referrer, not same-origin: a clinical URL should not travel anywhere.
        $response->headers->set('Referrer-Policy', 'no-referrer');
        $response->headers->set('Cross-Origin-Opener-Policy', 'same-origin');
        $response->headers->set('Cross-Origin-Resource-Policy', 'same-origin');
        $response->headers->set('X-Permitted-Cross-Domain-Policies', 'none');
        $response->headers->set(
            'Permissions-Policy',
            'camera=(), microphone=(), geolocation=(), payment=(), usb=()'
        );

        // HSTS only over TLS — sending it on plain HTTP is meaningless (RFC 6797 §7.2).
        if ($request->isSecure()) {
            $response->headers->set('Strict-Transport-Security', 'max-age=31536000; includeSubDomains');
        }

        // NOTHING behind the login is cacheable — not the census, not the staff roster,
        // not the admin console. A path allow-list was too narrow: a proxy or a shared
        // ward workstation would happily keep the last user's page.
        //
        // `$request->user()` is wrapped because this middleware is GLOBAL: on a stateless
        // route (the /up health check has no session middleware) the session guard throws
        // "Session store not set on request". Unwrapped, that 500s the health endpoint,
        // the container is marked unhealthy forever and the proxy stops routing to it —
        // found by the production smoke test, not by any request the app itself makes.
        $authenticated = false;

        try {
            $authenticated = $request->user() !== null;
        } catch (\Throwable) {
            // Stateless route: treat as anonymous.
        }

        if ($authenticated || $request->is('endorsement*')) {
            $response->headers->set('Cache-Control', 'no-store, no-cache, must-revalidate, max-age=0');
            $response->headers->set('Pragma', 'no-cache');
        }

        return $response;
    }
}
