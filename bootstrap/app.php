<?php

use App\Http\Middleware\EnforceTwoFactor;
use App\Http\Middleware\EnsureAccountActive;
use App\Http\Middleware\EnsureCapability;
use App\Http\Middleware\HandleInertiaRequests;
use App\Http\Middleware\SecurityHeaders;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->web(append: [
            // Per-request `active` re-check (legacy require_auth.php parity). Declared
            // BEFORE HandleInertiaRequests so a revoked account never gets its auth props shared.
            EnsureAccountActive::class,
            // Privileged accounts must carry a second factor (production default).
            EnforceTwoFactor::class,
            HandleInertiaRequests::class,
        ]);

        // Behind a load balancer / reverse proxy the app must believe X-Forwarded-Proto,
        // or every isSecure() check is false, HSTS is never sent and the session cookie
        // is issued without Secure. The proxy list is deployment config.
        // X_FORWARDED_HOST IS DELIBERATELY ABSENT. Laravel's password-reset broker builds
        // its link from the request host, so trusting a client-supplied X-Forwarded-Host
        // means an attacker can have a genuine reset link mailed to a victim pointing at
        // the attacker's domain — and the broker token, unlike a signed URL, is not bound
        // to a host. The app never needs a client to tell it its own hostname: APP_URL is
        // fixed. Proto/port/for are still honoured so isSecure() and audit IPs work behind
        // the proxy.
        $middleware->trustProxies(
            at: env('TRUSTED_PROXIES', '*'),
            headers: Request::HEADER_X_FORWARDED_FOR
                | Request::HEADER_X_FORWARDED_PORT
                | Request::HEADER_X_FORWARDED_PROTO,
        );

        // Second lock on the same door: reject a forged Host header outright. The app
        // container sits on the shared `coolify` network, so a co-tenant container can
        // reach it directly, bypassing Traefik's Host-based routing entirely.
        //
        // These are REGEX patterns, and loopback must be in the list: the container's
        // HEALTHCHECK requests http://127.0.0.1:8080/up, so rejecting that Host would mark
        // the container permanently unhealthy and stop the proxy routing to it — the same
        // outage a session-less /up already caused once. docker/smoke.sh asserts /up.
        $middleware->trustHosts(at: function (): array {
            $host = parse_url((string) config('app.url'), PHP_URL_HOST);

            return array_values(array_filter([
                $host ? '^'.preg_quote($host, '#').'$' : null,
                '^localhost$',
                '^127\.0\.0\.1$',
                '^\[::1\]$',
            ]));
        }, subdomains: false);

        // Security headers on EVERY response, error pages included — appended globally,
        // not just to the web group, so JSON errors and the health endpoint carry them too.
        $middleware->append(SecurityHeaders::class);

        // Data-driven access control (B5): `->middleware('cap:registry.manage')`.
        $middleware->alias([
            'cap' => EnsureCapability::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        // JSON error rendering for api/* AND any JSON-negotiating client (Laravel's default),
        // so e.g. a validation failure is a real 422 for XHR callers while Inertia form posts
        // (Accept: text/html) keep the redirect-with-errors flow the pages rely on.
        /*
         * PHI MUST NEVER REACH A LOG FILE.
         *
         * A QueryException carries the failing SQL *and its bindings* in getMessage() —
         * a single constraint violation would write a child's name and MRN into
         * storage/logs in plaintext, where nothing rotates it out of scope. Database
         * failures are therefore reported as their SQLSTATE only. Likewise the clinical
         * fields are never flashed to the (unencrypted-by-default) session on a
         * validation error.
         */
        $exceptions->dontFlash([
            'password', 'password_confirmation', 'current_password',
            'mrn', 'patient_name', 'dob', 'age', 'ward_unit',
            'disease', 'details', 'plan', 'nevent', 'reason',
            'signature_data', 'code',
        ]);

        $exceptions->reportable(function (\Illuminate\Database\QueryException $e) {
            \Illuminate\Support\Facades\Log::error('Database error', [
                'sqlstate' => $e->getCode(),
                // Deliberately NOT $e->getMessage() / getSql() / getBindings().
                'at' => $e->getFile().':'.$e->getLine(),
            ]);

            return false;   // stop the default handler re-logging the full message
        });

        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*') || $request->expectsJson(),
        );
    })->create();
