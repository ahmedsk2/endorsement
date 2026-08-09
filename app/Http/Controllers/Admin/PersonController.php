<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Models\Institution;
use App\Models\Person;
use App\Models\Position;
use App\Support\ContactVisibility;
use App\Support\PersonPresenter;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Admin → People (cap:people.manage). Munawib PE-01…03, LV-02…04, ST-04.
 *
 * PERSON-scoped, where Admin → Users is ACCOUNT-scoped. The difference is the point: a
 * roster-only person has no `users` row, so Admin → Users (whose list is
 * `User::query()->join('people', ...)`) cannot show them at all — and that person is frequently
 * the on-call consultant whose name is frozen onto a signed handover (D9).
 *
 * NOTHING HERE CREATES AN ACCOUNT. The invitation flow is the only path from a roster entry to a
 * credential (design §5.1), and `tests/Feature/Build/RosterNeverMintsCredentialsTest.php`
 * asserts at source level that this class never writes to `users`.
 *
 * `withTrashed()` is deliberate: people are deactivated and never deleted (owner ruling), and an
 * administrator who cannot SEE a retired person cannot bring them back. It is also why the four
 * named roles on `handover_signoffs` stay resolvable.
 */
class PersonController extends Controller
{
    public function index(Request $request): Response
    {
        $people = Person::withTrashed()
            ->withExists(['user as has_account'])
            ->orderBy('people.full_name')
            ->get();

        // ONE query for the whole roster's current level (finding 5) — Person::levelsAt() shares
        // its predicate with levelAt(), so this and every future set-wise consumer (Task 10's
        // promotion preview, P1d's rota grid) never invent a second copy.
        $levels = Person::levelsAt($people);

        return Inertia::render('Admin/People', [
            'people' => $people->map(fn (Person $p): array => PersonPresenter::one(
                $p,
                $request->user(),
                ['level' => ($l = $levels[(int) $p->getKey()] ?? null) === null ? null : [
                    'id' => (int) $l->getKey(),
                    'code' => (string) $l->code,
                    'name' => (string) $l->name,
                ]],
            ))->values()->all(),
            'positions' => Position::orderBy('id')->get(['id', 'name']),
            'contact_visibility' => ContactVisibility::current(),
            'contact_visibilities' => Institution::CONTACT_VISIBILITIES,
        ]);
    }

    /**
     * PE-02's department setting. Offered and validated from the SAME list
     * (`Institution::CONTACT_VISIBILITIES`) — the SignoffPickers discipline, applied here too.
     * Audited by KEY only, never by value: which setting a department chose is itself a fact
     * about how visible staff phone numbers are, and the audit trail's own rule (CLAUDE.md) is
     * ids/field-names/counts only.
     */
    public function updateVisibility(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'contact_visibility' => ['required', 'string', Rule::in(array_keys(Institution::CONTACT_VISIBILITIES))],
        ]);

        ContactVisibility::set($data['contact_visibility']);

        AuditLog::record(
            'contact_visibility_update',
            'key=contact_visibility',
            $request->user()->getKey(),
            $request->ip(),
        );

        return back()->with('status', 'Contact visibility updated.');
    }
}
