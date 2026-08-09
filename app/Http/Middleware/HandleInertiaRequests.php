<?php

namespace App\Http\Middleware;

use App\Support\AccessControl;
use Illuminate\Http\Request;
use Inertia\Middleware;

class HandleInertiaRequests extends Middleware
{
    /**
     * The root template that's loaded on the first page visit.
     *
     * @see https://inertiajs.com/server-side-setup#root-template
     *
     * @var string
     */
    protected $rootView = 'app';

    /**
     * Determines the current asset version.
     *
     * @see https://inertiajs.com/asset-versioning
     */
    public function version(Request $request): ?string
    {
        return parent::version($request);
    }

    /**
     * Define the props that are shared by default.
     *
     * @see https://inertiajs.com/shared-data
     *
     * @return array<string, mixed>
     */
    public function share(Request $request): array
    {
        $user = $request->user();

        return [
            ...parent::share($request),
            // Identity + effective capability KEYS. The Vue nav hides items via `auth.can`
            // (cosmetic only — the server-side `cap:` gate remains the real authority). A guest
            // sees a null user and an empty capability set.
            'auth' => [
                'user' => $user ? [
                    'id' => $user->id,
                    'member_name' => $user->member_name,
                    'full_name' => $user->full_name,
                    'position' => $user->position,
                ] : null,
                'can' => $user ? AccessControl::capabilitiesFor($user) : [],
            ],
            // The sidebar's unit list, from the `units` table rather than a hardcoded array in
            // AppLayout.vue (CLAUDE.md's pending exception, closed P1b). Codes and display names
            // only — no clinical data, and nothing a guest may not see, which is why an
            // unauthenticated request gets an empty list rather than the seeded four.
            'nav' => [
                'units' => $user ? \App\Models\Unit::navList() : [],
            ],
            // Which handover the visitor is arriving for. Server-side and in the app's
            // timezone on purpose: the browser clock is whatever the device says, and a
            // ward system should agree with the ward. No PHI — a greeting and a time that
            // is already pinned to the wall.
            'shift' => \App\Support\ShiftClock::now(),
            // One-shot flash surface for the layout's status/error banners.
            'flash' => [
                'status' => $request->session()->get('status'),
                'error' => $request->session()->get('error'),
                // New-day gap dialog payload: {unit, date, last_date}. Carries no PHI —
                // unit code and dates only (see EndorsementController::newDay).
                'carry_prompt' => $request->session()->get('carry_prompt'),
                // A freshly-minted invitation link, shown to its issuer exactly once. It is
                // a bearer credential: it lives in the session flash for a single response
                // and is never persisted in readable form, so losing it means issuing a new
                // invitation rather than looking this one up.
                'invitation_link' => $request->session()->get('invitation_link'),
                // Results of the two "prove it works" buttons: {ok, message}. Separate from
                // `status`/`error` on purpose — these render BESIDE the button that caused
                // them, because on a long settings or profile page the page-top banner is a
                // scroll away, and the answer to "did that work?" must not land off screen.
                'mail_test' => $request->session()->get('mail_test'),
                'push_test' => $request->session()->get('push_test'),
            ],
        ];
    }
}
