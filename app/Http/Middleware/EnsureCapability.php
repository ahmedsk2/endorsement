<?php

namespace App\Http\Middleware;

use App\Models\AuditLog;
use App\Support\AccessControl;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Route guard: `->middleware('cap:endorsement.edit')` requires the authenticated user to hold
 * the given capability. On denial it writes a PHI-free `access_denied` audit row and returns
 * 403. Unauthenticated requests are 401 here — but routes should pair `auth` BEFORE `cap`, so
 * the `auth` middleware owns the redirect-to-login and this branch is only defense-in-depth.
 */
class EnsureCapability
{
    public function handle(Request $request, Closure $next, string $capability): Response
    {
        $user = $request->user();

        if ($user === null) {
            // Fail closed. With the expected `auth`-then-`cap` ordering this is unreachable.
            abort(401);
        }

        if (! AccessControl::allows($user, $capability)) {
            AuditLog::record('access_denied', 'cap='.$capability, $user->getKey(), $request->ip());
            abort(403);
        }

        return $next($request);
    }
}
