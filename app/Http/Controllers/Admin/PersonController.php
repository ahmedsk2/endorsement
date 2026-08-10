<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\PersonBulkRequest;
use App\Http\Requests\Admin\PersonRequest;
use App\Models\AuditLog;
use App\Models\Institution;
use App\Models\Level;
use App\Models\Person;
use App\Models\Position;
// Type-hint only. `RosterNeverMintsCredentialsTest` is what keeps this class from ever WRITING
// an account — naming the class in a signature is not a write, and the guard's needles are the
// write shapes, not the symbol.
use App\Models\User;
use App\Support\AccessControl;
use App\Support\Calendar;
use App\Support\CapabilityGrant;
use App\Support\ContactVisibility;
use App\Support\Csv;
use App\Support\Invitations\BulkResend;
use App\Support\Invitations\InvitationStatus;
use App\Support\LevelAssignment;
use App\Support\LevelPickers;
use App\Support\PersonPresenter;
use App\Support\PersonStatus;
use App\Support\PositionChange;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

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
        return Inertia::render('Admin/People', $this->rosterProps($request));
    }

    /**
     * LV-04: a per-row expandable history panel, fetched on open rather than carried on every
     * roster load — Task 3's `Person::levelsAt()` already answers "current level" for all 60
     * rows in one query; a per-row SPAN LIST is a different, much larger shape that only the
     * expanded row needs. Same page component as `index()` (`preserveState` on the client keeps
     * the rest of the screen exactly as it was), with the roster re-sent alongside it because an
     * Inertia partial reload (`only: ['history']`) trims the RESPONSE to just the requested keys
     * regardless of what this method builds.
     *
     * `->withTrashed()` on the route (routes/web.php) is what lets this resolve for a retired
     * person at all — see that route's own comment.
     */
    public function history(Request $request, Person $person): Response
    {
        return Inertia::render('Admin/People', $this->rosterProps($request) + [
            'history' => [
                'person_id' => (int) $person->getKey(),
                'spans' => PersonPresenter::history($person),
            ],
        ]);
    }

    /** @return array<string, mixed> */
    private function rosterProps(Request $request): array
    {
        $people = Person::withTrashed()
            ->withExists(['user as has_account'])
            ->orderBy('people.full_name')
            ->get();

        // ONE query for the whole roster's current level (finding 5) — Person::levelsAt() shares
        // its predicate with levelAt(), so this and every future set-wise consumer (Task 10's
        // promotion preview, P1d's rota grid) never invent a second copy.
        $levels = Person::levelsAt($people);

        // AC-02's claim status, on the same one-query-for-the-page terms (P1c-2 Decision B). It is
        // passed as `$extra` and `PersonPresenter` is not modified at all: the presenter's BASE map
        // reaches every `rota.view` holder through `contactFree()` — that is every seeded position
        // — so a base key would publish "who has not claimed their account yet" to the whole
        // department. `RotaReadViewTest` asserts it never gets there.
        $invitations = InvitationStatus::forPeople($people, $request->user());

        $positions = Position::orderBy('id')->get(['id', 'name']);

        return $this->capabilityGrantProps($people, $request->user()) + [
            'people' => $people->map(fn (Person $p): array => PersonPresenter::one(
                $p,
                $request->user(),
                ['level' => ($l = $levels[(int) $p->getKey()] ?? null) === null ? null : [
                    'id' => (int) $l->getKey(),
                    'code' => (string) $l->code,
                    'name' => (string) $l->name,
                ],
                    'invitation' => $invitations[(int) $p->getKey()] ?? null],
            ))->values()->all(),
            'positions' => $positions,
            // WHICH of those positions this viewer may PLACE somebody at (review finding F2). The
            // full catalog above still ships and must: it is what renders an existing
            // Administrator's role NAME, and a screen that dropped position 0 outright would show
            // "Role 0" on every administrator's row. This is the narrower list the two role
            // <select>s offer, and `PositionChange::grantableBy()` is the ONE definition it and
            // `PositionChange::write()`'s refusal both come from — offer and write, one predicate
            // (D9). `people.manage` is the roster gate; making somebody an Administrator is the
            // ACCOUNT console's act, because position 0 carries `access.manage` by role default.
            'grantable_positions' => PositionChange::grantableBy(
                $request->user(),
                $positions->pluck('id')->map(intval(...))->all(),
            ),
            // LV-02's bulk "set level" picker. `App\Support\LevelPickers::bulkAssignable()` is
            // the ONE predicate this offer and `PersonBulkRequest`'s write-side rule both read
            // from (review finding 5) — every ACTIVE level, EXT included — unlike Task 10's
            // promotion (which moves the internal training ladder specifically and excludes EXT
            // by Owner Decision 2), this is a general correction tool and a person may
            // legitimately be marked at the external level through it.
            'levels' => LevelPickers::bulkAssignable()->get(['id', 'code', 'name']),
            'contact_visibility' => ContactVisibility::current(),
            'contact_visibilities' => Institution::CONTACT_VISIBILITIES,
            // LV-02's bulk resend cap (P1c-2 Task 4). ONE definition, offered and enforced from
            // the same constant: `BulkResend::CAP` is what the button states and what
            // `InvitationBulkResendRequest` validates against, so the screen can never promise a
            // batch size the endpoint refuses.
            'invitation_resend_cap' => BulkResend::CAP,
        ];
    }

    /**
     * AC-04's per-person roles panel, or NOTHING AT ALL (P1c-2 Decision F, Task 6).
     *
     * GATED ON `access.manage`, NOT ON THIS ROUTE'S `people.manage`. That is the whole security
     * content of the panel: `people.manage` is "who exists and what level they hold" (P1c
     * Decision A), and its holder is a departmental administrator who may rename a ward. If the
     * roster gate also decided who may see and set capabilities, it would be a path to
     * `access.manage` — a privilege escalation created by a UI convenience. The endpoint the
     * panel posts to sits in the `cap:access.manage` route group for the same reason; this is the
     * matching half, so the control is not offered where the endpoint would refuse it.
     *
     * ABSENT, NOT EMPTY, for a viewer without the capability — `PersonPresenter`'s discipline for
     * a withheld contact field, for the same reason: an empty override map and a withheld one
     * look identical on screen, and a future reader eventually renders one as the other.
     *
     * ONE KEY RATHER THAN A PER-PERSON `$extra`, and `PersonPresenter` is not touched at all. Its
     * BASE map reaches every `rota.view` holder through `contactFree()` — that is every seeded
     * position — so "who holds which capability" must not be able to arrive there by a later
     * refactor of the presenter's own map.
     *
     * TWO QUERIES FOR THE WHOLE SCREEN, at any roster size: the catalog, and one join for every
     * override on the page. The People screen's query cost may not move with the size of the
     * department (`InvitationStatusTest`'s budget case), and a per-row capability lookup is
     * exactly the N+1 that case exists to catch.
     *
     * @param  Collection<int, Person>  $people
     * @return array<string, mixed>
     */
    private function capabilityGrantProps(Collection $people, ?User $viewer): array
    {
        if ($viewer === null || ! AccessControl::allows($viewer, 'access.manage')) {
            return [];
        }

        return [
            'capability_grants' => [
                'capabilities' => CapabilityGrant::catalog(),
                'overrides' => CapabilityGrant::overridesByPerson(
                    $people->map(static fn (Person $p): int => (int) $p->getKey())->all(),
                ),
            ],
        ];
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
     * PE-01 update. Every field except `position` and `active` is written directly; those two go
     * through `PositionChange::apply()` and `App\Support\PersonStatus::apply()` inside the SAME
     * transaction, so a refusal (the last active Administrator, demoted OR deactivated) rolls
     * the whole edit back rather than leaving other fields half-saved.
     *
     * Review finding 4: before `PersonStatus::apply()` existed, `active` travelled inside the
     * plain `$person->update($data)` call below and never touched the linked account — a person
     * deactivated through this screen stayed able to log in.
     */
    public function update(PersonRequest $request, Person $person): RedirectResponse
    {
        $data = $request->validated();
        $position = (int) $data['position'];
        $active = (bool) $data['active'];
        $fields = array_keys($data);
        unset($data['position'], $data['active']);

        DB::transaction(function () use ($data, $position, $active, $person, $request): void {
            $person->update($data);

            PositionChange::apply($person, $position, $request);
            PersonStatus::apply($person, $active);
        });

        AuditLog::record(
            'person_update',
            'person='.$person->getKey().';fields='.implode(',', $fields),
            $request->user()->getKey(),
            $request->ip(),
        );

        return back()->with('status', 'Person updated.');
    }

    /**
     * LV-02's bulk operations: set level, set status (active), export.
     *
     * Bulk "resend invitations" is deliberately NOT one of them. It is an ACCOUNT action, so it
     * posts to `InvitationController`'s own bulk endpoints under `ManagerScope`'s two-tier gate
     * (P1c-2 Task 4) — never here, where the gate is `cap:people.manage`. The selection comes from
     * this screen; the authorization does not, and routing a credential operation through the
     * roster's capability would make `people.manage` a path to minting bearer links.
     *
     * THE ORDER IS THE FEATURE (findings 12 and 13):
     *
     *  1. load the WHOLE selection once, ids de-duplicated, `withTrashed()` — a retired person
     *     is a legitimate member of a bulk selection (exporting a full roster that includes
     *     leavers, or reactivating one);
     *  2. refuse the WHOLE submission before touching anything if any part of it is invalid.
     *     `PersonBulkRequest`'s `ids.*` validation already does this structurally: Laravel
     *     resolves and validates the FormRequest before this method ever runs, so an unknown id
     *     fails the whole request with NO write attempted for the valid ones — the
     *     `InvitationController::store()` ordering finding 12 names, without a second copy of
     *     the mechanism;
     *  3. for `set_active` with `active = false`, the SET-AWARE last-administrator guard
     *     (finding 13, `PositionChange::wouldLeaveNoActiveAdministrator()`) — computed ONCE,
     *     outside any loop, over the whole selection, before the transaction opens. A per-row
     *     `isLastActiveAdministrator()` check here would let the last two Administrators through
     *     one at a time, each seeing the other as cover;
     *  4. one `DB::transaction` applying every change and collecting each person's own outcome
     *     from the writer's own return value, never from a guess made before the write;
     *  5. AFTER the commit, one audit summary row plus one row per person (Decision H's
     *     ordering, matching `AccessControlController::applyRoleSet()` — `AuditLog::record()`
     *     opens its own transaction and locks the chain tail, so nesting it inside this one would
     *     serialise the whole chain for the batch's duration and unwind the attempt's own trail
     *     with a rollback);
     *  6. `back()->with('bulk_report', ...)` so the screen shows what actually happened per
     *     person, not "Done."
     *
     * `export` is a READ: it short-circuits immediately after loading, before any of the above,
     * because none of it — the last-admin guard, the transaction, the per-person audit rows —
     * applies to a request that changes nothing.
     */
    public function bulk(PersonBulkRequest $request): RedirectResponse|StreamedResponse
    {
        $data = $request->validated();
        $ids = array_values(array_unique(array_map('intval', $data['ids'])));

        /** @var Collection<int, Person> $people */
        $people = Person::withTrashed()->whereIn('id', $ids)->get()->keyBy(
            fn (Person $p): int => (int) $p->getKey()
        );

        if ($data['action'] === 'export') {
            return $this->export($people->values(), $request);
        }

        $actorId = (int) $request->user()->getKey();
        $ip = $request->ip();

        if ($data['action'] === 'set_active' && $data['active'] === false) {
            $linkedUserIds = $people->map(fn (Person $p): ?int => $p->user()->first()?->getKey())
                ->filter()
                ->map(fn ($id): int => (int) $id)
                ->values()
                ->all();

            if (PositionChange::wouldLeaveNoActiveAdministrator($linkedUserIds)) {
                throw ValidationException::withMessages([
                    'ids' => 'This selection would deactivate every active Administrator — '.
                        'leave at least one, or grant another account the role first.',
                ]);
            }
        }

        $outcomes = [];

        DB::transaction(function () use ($people, $data, $actorId, &$outcomes): void {
            foreach ($people as $id => $person) {
                $outcomes[$id] = match ($data['action']) {
                    'set_level' => LevelAssignment::assign(
                        $person,
                        Level::findOrFail((int) $data['level_id']),
                        Calendar::todayYmd(),
                        ['actor' => $actorId],
                    ),
                    'set_active' => $this->applySetActive($person, (bool) $data['active']),
                };
            }
        });

        AuditLog::record('person_bulk', 'action='.$data['action'].';n='.count($outcomes), $actorId, $ip);

        foreach ($outcomes as $personId => $outcome) {
            AuditLog::record('person_bulk_item', 'person='.$personId.';outcome='.$outcome, $actorId, $ip);
        }

        return back()->with('bulk_report', $outcomes);
    }

    /**
     * `App\Support\PersonStatus::apply()` keeps `people.active` and the linked account's
     * `users.active` in step — the same pairing `UserManagementController::setActive()` keeps
     * from the account side ("the admin screen offers one control, so this is where they are
     * kept in step"). Whichever console the control is on, a leaver stops being NAMED as well as
     * stops being able to log in.
     */
    private function applySetActive(Person $person, bool $active): string
    {
        PersonStatus::apply($person, $active);

        return $active ? 'activated' : 'deactivated';
    }

    /**
     * A read: no transaction, no per-person audit row, no last-admin guard. Columns come from
     * `App\Support\PersonPresenter` — the SAME code that enforces contact visibility on screen
     * (Decision B) — so a viewer who cannot see phone numbers gets a file with NO Phone column at
     * all, never a column of blanks (a blank column invites "the numbers are missing, let me get
     * them another way"). Every cell is neutralised by `Csv::stream()` on the way out — a staff
     * name or note beginning `=`, `+`, `-`, `@`, TAB or CR cannot execute as a formula on open.
     *
     * @param  Collection<int, Person>  $people
     */
    private function export(Collection $people, Request $request): StreamedResponse
    {
        $positions = Position::orderBy('id')->get(['id', 'name'])->keyBy('id');
        $levels = Person::levelsAt($people);

        $projected = $people->map(fn (Person $p): array => PersonPresenter::one(
            $p,
            $request->user(),
            ['level' => ($l = $levels[(int) $p->getKey()] ?? null)?->code],
        ))->values();

        AuditLog::record(
            'person_bulk',
            'action=export;n='.$projected->count(),
            $request->user()?->getKey(),
            $request->ip(),
        );

        ['headers' => $headers, 'rows' => $rows] = self::exportTable($projected, $positions);

        return Csv::stream('roster.csv', $headers, $rows);
    }

    /**
     * PURE, and deliberately `public static` rather than folded inline into `export()`: this is
     * the only piece of the export that can be exercised directly, because `PersonPresenter`'s
     * phone-suppression can only be OBSERVED with a viewer who does not hold `people.manage` —
     * and the route this method's one caller sits behind (`cap:people.manage`) means a real
     * request here never has one, the same structural note Task 2's `ContactFieldsAreProjectedOnceTest`
     * amendment records for the People screen itself. `PeopleBulkTest` calls this directly with a
     * hand-built projected array to prove the Phone COLUMN — not the phone VALUE, which
     * `ContactVisibilityTest` already covers — tracks the presence of the `phone` key.
     *
     * @param  Collection<int, array<string, mixed>>  $projected  App\Support\PersonPresenter::one() shape
     * @param  Collection<int, Position>  $positions  keyed by id
     * @return array{headers: list<string>, rows: iterable<list<string>>}
     */
    public static function exportTable(Collection $projected, Collection $positions): array
    {
        $hasPhone = $projected->isNotEmpty() && array_key_exists('phone', $projected->first());

        $headers = ['Full name', 'Short name', 'Role', 'Level', 'Status', 'Account'];
        if ($hasPhone) {
            $headers[] = 'Phone';
        }

        $rows = $projected->map(function (array $p) use ($positions, $hasPhone): array {
            $row = [
                $p['full_name'],
                $p['short_name'] ?? '',
                $positions[$p['position']]->name ?? (string) $p['position'],
                $p['level'] ?? '',
                $p['active'] ? 'Active' : 'Retired',
                $p['has_account'] ? 'Account' : 'Roster only',
            ];

            if ($hasPhone) {
                $row[] = $p['phone'] ?? '';
            }

            return $row;
        });

        return ['headers' => $headers, 'rows' => $rows];
    }
}
