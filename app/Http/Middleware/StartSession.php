<?php

namespace App\Http\Middleware;

use Illuminate\Http\Request;
use Illuminate\Session\Middleware\StartSession as BaseStartSession;

/**
 * Laravel's session starter, with one thing excluded from "where the user was".
 *
 * The framework records `_previous.url` for any non-AJAX GET that resolves to a route. A
 * browser loading `<img src="/signatures/me">` is exactly that — method GET, resolves to a
 * named route, no `X-Requested-With` — so the signature preview on the profile page
 * overwrote the session's idea of the previous page with the URL of a PNG.
 *
 * Every `back()` afterwards then redirected to that image. Inertia followed the redirect,
 * received `Content-Type: image/png` with no `X-Inertia` header, and did what it does with
 * any non-Inertia response: opened its error dialog — a full-viewport iframe rendering the
 * raw bytes. That is the wall of "PNG IHDR … IDAT … IEND" that appeared after saving a
 * signature and stopped the moment the signature was removed.
 *
 * A sub-resource is never somewhere a person can navigate back to, so routes whose only
 * consumer is an `<img>` or a `<link>` are marked `->defaults('no_history', true)` and
 * skipped here. See also TrackInertiaPreviousUrl, which handles the opposite half of the
 * same problem: Inertia page visits ARE navigations, and the parent skips those too.
 */
class StartSession extends BaseStartSession
{
    protected function storeCurrentUrl(Request $request, $session)
    {
        if ($request->route()?->defaults['no_history'] ?? false) {
            return;
        }

        parent::storeCurrentUrl($request, $session);
    }
}
