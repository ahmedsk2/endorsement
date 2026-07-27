<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Teach `back()` where the user actually is.
 *
 * `back()` asks UrlGenerator::previous(), which prefers the `Referer` header and falls back
 * to the session's `_previous.url`. In this application BOTH are wrong:
 *
 *  - `Referrer-Policy: no-referrer` is set on every response (SecurityHeaders), deliberately
 *    and on purpose — a URL in this app can name a unit and a date, and it should not travel
 *    to anywhere the browser happens to go next. So there is never a Referer to use.
 *  - Laravel records `_previous.url` only for NON-AJAX GETs, and every Inertia visit sends
 *    `X-Requested-With: XMLHttpRequest`. So moving around the app never updates it. It stays
 *    pinned to the last full document load for the whole 60-minute session.
 *
 * The result was three separate-looking bugs with one cause. Saving on the profile sent the
 * user to Admin → Settings, because that was the last page they had hard-loaded. And the
 * signature preview — a plain `<img src="/signatures/me">`, which IS a non-AJAX GET on a
 * session-backed route — overwrote the pointer with the image URL, so every later `back()`
 * redirected to a PNG. Inertia, receiving a response with no `X-Inertia` header, opened its
 * error dialog: a full-viewport iframe showing the raw bytes of the signature.
 *
 * An Inertia GET is a page navigation in every sense that matters here, so it is recorded as
 * one. Sub-resources the browser fetches on a page's behalf are excluded by the `no_history`
 * route default — an `<img>` is never somewhere a person can go "back" to.
 */
class TrackInertiaPreviousUrl
{
    public function handle(Request $request, Closure $next): Response
    {
        $response = $next($request);

        if (! $request->isMethod('GET') || ! $request->hasHeader('X-Inertia')) {
            return $response;
        }

        // Only a real page. A redirect is somewhere the user is being sent FROM, and an
        // error is not a destination to return to.
        if ($response->isSuccessful() && $request->hasSession()) {
            $request->session()->setPreviousUrl($request->fullUrl());
        }

        return $response;
    }
}
