<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\PersonRequest;
use App\Models\AuditLog;
use App\Models\Institution;
use App\Models\Person;
use App\Models\Position;
use App\Support\ContactVisibility;
use App\Support\PersonPresenter;
use App\Support\PositionChange;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
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

    /**
     * PE-01 create. NOTHING HERE CREATES AN ACCOUNT — see this class's own docblock.
     *
     * `position` is written twice, deliberately: once in the initial `Person::create()` (the
     * column is NOT NULL with no default, so the insert must supply a value) and once through
     * `PositionChange::apply()`, which is what actually decides whether that value survives. A
     * brand-new person has no linked `users` row yet, so the last-admin guard can never fire
     * here — but every position write, including this one, goes through the SAME definition
     * (Decision C) rather than a create-time exception to it.
     */
    public function store(PersonRequest $request): RedirectResponse
    {
        $data = $request->validated();
        $position = (int) $data['position'];

        $person = DB::transaction(function () use ($data, $position, $request): Person {
            $person = Person::create($data);

            PositionChange::apply($person, $position, $request);

            return $person;
        });

        AuditLog::record(
            'person_create',
            'person='.$person->getKey().';fields='.implode(',', array_keys($data)),
            $request->user()->getKey(),
            $request->ip(),
        );

        return back()->with('status', 'Person created.');
    }

    /**
     * PE-01 update. Every field except `position` is written directly; `position` goes through
     * `PositionChange::apply()` inside the SAME transaction, so a refusal (the last active
     * Administrator) rolls the whole edit back rather than leaving other fields half-saved.
     */
    public function update(PersonRequest $request, Person $person): RedirectResponse
    {
        $data = $request->validated();
        $position = (int) $data['position'];
        $fields = array_keys($data);
        unset($data['position']);

        DB::transaction(function () use ($data, $position, $person, $request): void {
            $person->update($data);

            PositionChange::apply($person, $position, $request);
        });

        AuditLog::record(
            'person_update',
            'person='.$person->getKey().';fields='.implode(',', $fields),
            $request->user()->getKey(),
            $request->ip(),
        );

        return back()->with('status', 'Person updated.');
    }
}
