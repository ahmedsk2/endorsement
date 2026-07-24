<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

/**
 * G6-S2 — re-check `users.active` on EVERY authenticated request.
 *
 * The legacy app does this in `require_auth.php` (it re-reads `active = 1` per request); the
 * Laravel port lost it, so deactivating an account in the admin console blocked only the NEXT
 * LOGIN — a departing or suspended staff member kept full PHI access on their live session until
 * they happened to log out. Revocation has to be immediate, so a deactivated account is logged
 * out, its session invalidated, and its CSRF token rotated on the very next request.
 *
 * Runs inside the `web` group (after session + auth resolution) and is a no-op for guests.
 */
class EnsureAccountActive
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if ($user !== null && ! $user->active) {
            Auth::guard('web')->logout();
            $request->session()->invalidate();
            $request->session()->regenerateToken();

            // No PHI in the message — the account id is not disclosed to the client either.
            return $request->expectsJson()
                ? response()->json(['message' => 'This account is no longer active.'], 401)
                : redirect()->guest(route('login'))
                    ->withErrors(['email' => 'This account is no longer active. Contact an administrator.']);
        }

        return $next($request);
    }
}
