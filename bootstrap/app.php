<?php

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
            HandleInertiaRequests::class,
        ]);

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
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*') || $request->expectsJson(),
        );
    })->create();
