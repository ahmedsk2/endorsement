<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Models\Capability;
use App\Models\Person;
use App\Models\Position;
use App\Models\RoleCapability;
use App\Models\User;
use App\Support\AccessControl;
use App\Support\CapabilityGrant;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

/**
 * B6 — Admin → Access Control. Lets an administrator edit the role→capability defaults and
 * per-user grant/deny overrides at runtime. The seeded day-one defaults (AccessControlSeeder)
 * reproduce the legacy PERMISSION-MATRIX server gates; this controller only makes them editable.
 *
 * Every route is `auth` + `cap:access.manage` (admin-only). Every write validates capability
 * ids/effects against the known catalog (never free-form), audits PHI-free (ids/counts only),
 * and busts the resolver cache (generation bump for role changes; per-user forget for user
 * overrides).
 *
 * AC-04 (P1c-2 Task 6) added `updatePerson()` beside `updateUser()`. It is the endpoint the
 * People screen's roles panel posts to, and it sits in THIS group deliberately: the People
 * screen's own group is `cap:people.manage`, and serving a role-granting control from there
 * would let a roster manager grant themselves `access.manage`. Both endpoints delegate to
 * `App\Support\CapabilityGrant`, so the two surfaces cannot drift — the only difference between
 * them is which id the caller happens to hold, an account's or a person's.
 */
class AccessControlController extends Controller
{
    /** Valid role ids (0=Administrator .. 4=Resident). */
    private const POSITIONS = [0, 2, 3, 4, 5];

    public function index(Request $request): Response
    {
        // ONE catalog definition, shared with the People screen's panel and with the writer's own
        // validation — the offer and the write-side rule read from the same list (D9).
        $capabilities = CapabilityGrant::catalog();

        // position => list of capability ids that role currently holds.
        $roleMatrix = [];
        foreach (self::POSITIONS as $position) {
            $roleMatrix[$position] = RoleCapability::where('position', $position)
                ->pluck('capability_id')
                ->map(static fn ($id): int => (int) $id)
                ->all();
        }

        return Inertia::render('Admin/AccessControl', [
            'capabilities' => $capabilities,
            'positions' => Position::orderBy('id')->get(['id', 'name']),
            'roleMatrix' => $roleMatrix,
            // The whole (small) roster is shipped so the picker searches instantly client-side.
            // Only ACCOUNTS appear here: a per-user capability override is meaningless for
            // someone who has no account, and since P0c the roster lives in `people`, so this
            // list is small again by construction rather than by hope.
            //
            // `select('users.*')` (not a narrow people.full_name/position select): `full_name`
            // and `position` are read-through accessors now, and they resolve via the `person`
            // relation, which needs `person_id` loaded. A narrow select that omits it makes the
            // accessor silently return null even though the join fetched the right value —
            // proven by a throwaway test before this fix landed.
            'users' => User::query()
                ->join('people', 'people.id', '=', 'users.person_id')
                ->orderBy('people.full_name')
                ->select('users.*')
                ->get()
                ->map(fn (User $u): array => [
                    'id' => $u->id,
                    'member_name' => $u->member_name,
                    'full_name' => $u->full_name,
                    'position' => (int) $u->position,
                ])
                ->all(),
            'selectedUser' => $this->selectedUser($request),
        ]);
    }

    /**
     * Replace one role's capability set with exactly the submitted set (delete removed rows,
     * insert added rows) inside a transaction, then bump the generation to bust every cache.
     */
    public function updateRole(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'position' => ['required', 'integer', Rule::in(self::POSITIONS)],
            'capability_ids' => ['present', 'array'],
            'capability_ids.*' => ['integer', 'distinct', 'exists:capabilities,id'],
        ]);

        $this->applyRoleSet(
            (int) $data['position'],
            array_values(array_unique(array_map('intval', $data['capability_ids']))),
            $request,
        );

        return back()->with('status', 'Role defaults updated.');
    }

    /**
     * Replace the WHOLE role matrix in one submission — the page has a single Save button, so
     * one click is one atomic change with one confirmation, not five. Every role is validated
     * BEFORE anything is written (a rejected Administrator set must not leave the other four
     * roles half-applied); the per-capability audit rows are identical to the single-role path.
     */
    public function updateRoles(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'roles' => ['present', 'array'],
            'roles.*' => ['present', 'array'],
            // No `distinct` here: on a nested wildcard it compares across EVERY role, so two
            // roles legitimately holding the same capability would be rejected. Duplicates
            // within a role are harmless and are collapsed by array_unique below.
            'roles.*.*' => ['integer', 'exists:capabilities,id'],
        ]);

        $matrix = [];

        foreach ($data['roles'] as $position => $capabilityIds) {
            $position = (int) $position;

            if (! in_array($position, self::POSITIONS, true)) {
                throw ValidationException::withMessages([
                    'roles' => 'Unknown role in the submitted matrix.',
                ]);
            }

            $matrix[$position] = array_values(array_unique(array_map('intval', $capabilityIds)));
        }

        // Validate the self-lockout guard across the whole matrix first — all-or-nothing.
        foreach ($matrix as $position => $desired) {
            $this->assertNoSelfLockout($position, $desired);
        }

        DB::transaction(function () use ($matrix, $request): void {
            foreach ($matrix as $position => $desired) {
                $this->applyRoleSet($position, $desired, $request, guard: false);
            }
        });

        return back()->with('status', 'Role defaults updated.');
    }

    /**
     * Replace ONE role's capability set with exactly the submitted set (delete removed rows,
     * insert added rows), bump the generation, and audit per capability.
     *
     * @param  list<int>  $desired
     */
    private function applyRoleSet(int $position, array $desired, Request $request, bool $guard = true): void
    {
        if ($guard) {
            $this->assertNoSelfLockout($position, $desired);
        }

        [$toAdd, $toRemove] = DB::transaction(function () use ($position, $desired): array {
            $current = RoleCapability::where('position', $position)
                ->pluck('capability_id')
                ->map(static fn ($id): int => (int) $id)
                ->all();

            $toRemove = array_values(array_diff($current, $desired));
            $toAdd = array_values(array_diff($desired, $current));

            if ($toRemove !== []) {
                RoleCapability::where('position', $position)
                    ->whereIn('capability_id', $toRemove)
                    ->delete();
            }

            foreach ($toAdd as $capabilityId) {
                RoleCapability::create([
                    'position' => $position,
                    'capability_id' => $capabilityId,
                ]);
            }

            return [$toAdd, $toRemove];
        });

        // Role defaults changed -> invalidate every user's cached set.
        AccessControl::flush();

        $actorId = $request->user()->getKey();
        $ip = $request->ip();

        AuditLog::record(
            'access_role_update',
            'position='.$position.';caps='.count($desired),
            $actorId,
            $ip,
        );

        // One row PER capability changed, so "why did this come back / go away?" is answerable
        // from the trail by capability, not merely by count. Capability KEYS are configuration,
        // never PHI.
        $keys = $this->capabilityKeys(array_merge($toAdd, $toRemove));

        foreach ($toAdd as $capabilityId) {
            AuditLog::record('access_role_grant', 'position='.$position.';cap='.($keys[$capabilityId] ?? $capabilityId), $actorId, $ip);
        }

        foreach ($toRemove as $capabilityId) {
            AuditLog::record('access_role_revoke', 'position='.$position.';cap='.($keys[$capabilityId] ?? $capabilityId), $actorId, $ip);
        }
    }

    /**
     * The Administrator role (0) must always keep `access.manage`, otherwise this page becomes
     * unreachable and access control could never be edited again (self-lockout).
     *
     * @param  list<int>  $desired
     */
    private function assertNoSelfLockout(int $position, array $desired): void
    {
        if ($position !== 0) {
            return;
        }

        $accessManageId = (int) Capability::where('key', 'access.manage')->value('id');

        if ($accessManageId !== 0 && ! in_array($accessManageId, $desired, true)) {
            throw ValidationException::withMessages([
                'capability_ids' => "The Administrator role cannot give up 'access.manage'.",
            ]);
        }
    }

    /**
     * Replace a user's explicit overrides with exactly the submitted map (upsert grant/deny,
     * delete any override omitted from the map) inside a transaction, then bust that user's cache.
     *
     * The body of that act lives in `App\Support\CapabilityGrant` since P1c-2 Task 6, so this
     * console and the People screen's panel share it rather than carrying two copies. Nothing
     * about the behaviour moved — the catalog check, the in-transaction diff, the flush and the
     * summary-plus-per-change audit rows are the same code, called from one place instead of two.
     *
     * `withTrashed()` is deliberate and is what the raw `exists:users,id` rule above already
     * meant: `Rule::exists` runs on the query builder and never sees the SoftDeletes global
     * scope, so a trashed id has always passed validation here. Nothing in this codebase sets
     * `users.deleted_at` (account deletion was withdrawn as a capability on 2026-07-19), so this
     * matches the same set either way — it just does not turn a validation rule's blind spot
     * into a 404 that never used to happen.
     */
    public function updateUser(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'user_id' => ['required', 'integer', 'exists:users,id'],
            'overrides' => ['present', 'array'],
            'overrides.*' => [Rule::in(['grant', 'deny'])],
        ]);

        $user = User::withTrashed()->findOrFail((int) $data['user_id']);

        CapabilityGrant::applyForUser($user, $data['overrides'], $request->user(), $request->ip());

        return back()->with('status', 'User overrides updated.');
    }

    /**
     * AC-04 — the same act addressed by PERSON, which is how the People screen reaches it.
     *
     * WHY THIS ENDPOINT IS IN THIS FILE AND NOT `PersonController`. It is served from the
     * `cap:access.manage` group; the People screen's own group is `cap:people.manage`, scoped in
     * P1c Decision A as "who exists and what level they hold" and explicitly not the account
     * console. A role-granting control hung off the roster gate would let its holder — a
     * departmental administrator who may rename a ward — grant themselves `access.manage`, which
     * is a privilege escalation created by a UI convenience. The screen is shared; the
     * authorization is not, exactly as it already is for the Invite and Resend controls beside it.
     *
     * THE GRANT STILL LANDS ON THE ACCOUNT (owner decision 2). This method resolves the person's
     * linked `users` row and hands it to the same writer; `user_capabilities.user_id` does not
     * move, and `AccessControl::resolve()`/`holdersOf()`/the cache key are untouched.
     *
     * A PERSON WITH NO ACCOUNT IS REFUSED WITH A SENTENCE. A roster-only person has no `users`
     * row and cannot authenticate by construction (D3), so there is nothing for a capability to
     * attach to. Saying so is the point: a control that silently does nothing is the shape this
     * programme has refused twice already.
     */
    public function updatePerson(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'person_id' => ['required', 'integer', 'exists:people,id'],
            'overrides' => ['present', 'array'],
            'overrides.*' => [Rule::in(['grant', 'deny'])],
        ]);

        // `withTrashed()` mirrors the People screen, which lists retired colleagues on purpose
        // (people are deactivated, never deleted) — and `exists:people,id` never saw the
        // soft-delete scope anyway, so refusing here would be a new refusal, not a preserved one.
        $person = Person::withTrashed()->findOrFail((int) $data['person_id']);
        $user = $person->user;

        if ($user === null) {
            throw ValidationException::withMessages([
                'person_id' => 'This person has no account. Roles are granted to an account — invite them first.',
            ]);
        }

        CapabilityGrant::applyForUser($user, $data['overrides'], $request->user(), $request->ip());

        return back()->with('status', 'Roles updated.');
    }

    /**
     * capability id => dot-notation key, for the given ids. Used to name capabilities in the
     * audit trail (a key is configuration, never PHI).
     *
     * @param  array<int, int>  $capabilityIds
     * @return array<int, string>
     */
    private function capabilityKeys(array $capabilityIds): array
    {
        if ($capabilityIds === []) {
            return [];
        }

        return Capability::whereIn('id', $capabilityIds)
            ->pluck('key', 'id')
            ->mapWithKeys(static fn ($key, $id): array => [(int) $id => (string) $key])
            ->all();
    }

    /**
     * The chosen user's effective capability keys + explicit overrides, or null when no
     * `user_id` is supplied. Powers the per-user override editor on the page.
     *
     * @return array{id: int, member_name: ?string, full_name: ?string, position: int, effective: array<int, string>, overrides: array<int, string>}|null
     */
    private function selectedUser(Request $request): ?array
    {
        $userId = $request->query('user_id');

        // A query value is a string OR AN ARRAY. `?user_id[]=1` reached `User::find()` as an array,
        // where it means findMany and returns a COLLECTION — which is not null, so the guard below
        // passed it straight through to `$user->getKey()` and out as a `BadMethodCallException`,
        // rendered as a 500. Guarded on the SHAPE rather than on the sink: an unusable `user_id`
        // now selects nobody, exactly as an unknown id already did.
        if (! is_string($userId)) {
            return null;
        }

        // AN UNBOUND ACCOUNT IS NOT A SELECTABLE ACCOUNT (review F5), and this
        // `whereNotNull('person_id')` is the same set `index()`'s picker above already produces
        // with its inner join on `people` — stated as one predicate on both sides so the list and
        // the detail panel cannot disagree about which accounts exist.
        //
        // It is not a cosmetic disagreement. `full_name` and `position` are read-through
        // accessors onto the linked Person (P0c), so both answer NULL once `AccountUnbind` has
        // cleared the link — and the projection below casts `(int) $user->position`, where
        // `(int) null` is 0, the ADMINISTRATOR role id. `AccessControl.vue` renders
        // `positionName(0)` as "Administrator" beside the login name the panel falls back to, so
        // a dead, nameless account was announced as the most privileged role this system has, on
        // the one screen whose whole subject is privilege. Every other read of this link fails
        // toward a blank; this one failed toward the top of the ladder.
        //
        // Returning null rather than throwing is deliberate: it is already this method's answer
        // for an unknown or array-shaped `user_id`, so the page renders its existing
        // no-selection state and no Vue change is needed to describe a new refusal.
        $user = User::query()->whereNotNull('person_id')->find($userId);
        if ($user === null) {
            return null;
        }

        // One projection of "what does this account explicitly override", shared with the People
        // panel — the read moved to `CapabilityGrant` alongside the write so that the guard which
        // keeps `user_capabilities` to one writer has nothing to allow-list here.
        $overrides = CapabilityGrant::overridesFor($user);

        return [
            'id' => (int) $user->getKey(),
            'member_name' => $user->member_name,
            'full_name' => $user->full_name,
            'position' => (int) $user->position,
            'effective' => AccessControl::capabilitiesFor($user),
            'overrides' => $overrides,
        ];
    }
}
