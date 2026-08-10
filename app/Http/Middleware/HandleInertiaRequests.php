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
                // LV-02's bulk operations report: {person_id: outcome}, from the writer's own
                // return values (LevelAssignment::assign()'s outcome constants, or
                // 'activated'/'deactivated') — never a client-side guess at what happened.
                'bulk_report' => $request->session()->get('bulk_report'),
                // LV-03's promotion preview/commit results (App\Support\Promotion). Same
                // one-shot flash channel — a stale preview is cleared by the next navigation,
                // which is exactly the property "changing any of the three inputs clears the
                // preview" needs.
                'promotion_preview' => $request->session()->get('promotion_preview'),
                'promotion_result' => $request->session()->get('promotion_result'),
                // ST-04's roster import (P1c Task 12). Same one-shot flash channel and the same
                // reason: a stale preview must not survive the next navigation, and a fresh
                // upload clears it client-side before either of these is ever consulted again.
                'roster_preview' => $request->session()->get('roster_preview'),
                'roster_result' => $request->session()->get('roster_result'),
                // MR-06's bulk rota moves (P1d-2 Tasks 8/9). Same one-shot channel, same reason,
                // and it is the reason this whole feature reaches a screen at all: Task 8 shipped
                // `back()->with('rota_fill_preview', …)` without an entry here, and a session key
                // no `share()` names is invisible to every page in the app — the preview existed
                // only in a feature test. `RotaFillCommitTest::test_the_preview_and_the_result_
                // reach_the_screen_as_shared_flash_props` asserts both halves through a real
                // second request.
                //
                // ONE-SHOT IS THE POINT, not an accident of the mechanism: a fill plan is pinned
                // to the rota it was computed against (`RotaFill::digest()`), so a plan surviving
                // a navigation would be a plan the operator could confirm against a grid they
                // have since changed themselves.
                //
                // Ids and counts only — `RotaFill::plan()` strips the resolved Eloquent models
                // (`context`) before returning, because a props payload built from whole models
                // is how a contact field reaches a page nobody meant to put it on (Decision C).
                'rota_fill_preview' => $request->session()->get('rota_fill_preview'),
                'rota_fill_result' => $request->session()->get('rota_fill_result'),
                // MR-06's import (P1d-2 Task 12). Same one-shot channel, same reason, and listed
                // here for the reason the entry above it was added a task late: a session key no
                // `share()` names is invisible to every page in the app, and all four suites stay
                // green while the feature reaches nobody.
                //
                // ONE-SHOT IS THE POINT here too: the commit is pinned to the sha256 of the exact
                // bytes the preview parsed (`RotaImport::digest()`), so an analysis surviving a
                // navigation would be an analysis the operator could confirm against a file they
                // have since re-exported.
                //
                // Ids, counts and the file's own cells only — `RotaImport::preview()` strips the
                // resolved Eloquent models (`context`) before returning, because a props payload
                // built from whole models is how a contact field reaches a page nobody meant to
                // put it on (Decision C).
                'rota_import_preview' => $request->session()->get('rota_import_preview'),
                'rota_import_result' => $request->session()->get('rota_import_result'),
            ],
        ];
    }
}
