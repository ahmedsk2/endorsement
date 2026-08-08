<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Models\PendingRegistration;
use App\Models\Position;
use App\Models\User;
use App\Support\AccessControl;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

/**
 * F1 — Admin → Users (re-platform of legacy control.php). Approves/rejects pending
 * self-registrations and activates/deactivates accounts or changes roles. Every write is
 * audited PHI-free (ids only), and passwords NEVER appear in props, logs, or audit details.
 *
 * TWO TIERS OF ACCESS:
 *  - `users.manage` (Administrator): everything, every account.
 *  - `users.manage_residents` (Chief Resident): the SCOPED tier — sees, approves,
 *    activates and deactivates RESIDENT (position 4) accounts alone. No role changes,
 *    no profile edits, no non-resident accounts. Enforced in-controller (the shared
 *    routes carry `auth` only) so the refusal can be audited with the attempted target.
 *
 * Two hard invariants:
 *  - The pending row's ALREADY-HASHED password is copied VERBATIM through the query builder
 *    (exactly like LegacyImport) so the `hashed` model cast can never re-derive it.
 *  - The LAST remaining active Administrator (position 0) can be neither deactivated nor
 *    demoted (self-lockout guard) — including by themselves.
 *
 * `pending_registrations` has had NO WRITER since 2026-07-27 — `/register` is closed
 * (`RegisteredUserController::closed()`) and nothing in `app/` calls
 * `EmailVerificationController::sendRegistrationLink()`. It is a frozen legacy queue, not a live
 * state machine, and its only remaining exits are `approve()`, `reject()`, and the 30-day
 * `data:retention` sweep. Removing the queue entirely is DEFERRED until
 * `InvitationController::pendingCount()` reads zero on production — the owner observes that and
 * decides; this class does not.
 */
class UserManagementController extends Controller
{
    /**
     * Valid role ids. Position 1 (Nurse) is RETIRED — an endorsement-only system has no
     * nurse surface (the legacy gate excluded them), so the value is invalid everywhere.
     * Position 0 is grantable ONLY here; 5 = Chief Resident (promoted, never registered).
     */
    private const POSITIONS = [0, 2, 3, 4, 5];

    /** The one position a scoped (Chief Resident) manager may act on. */
    private const RESIDENT = 4;

    public function index(Request $request): Response
    {
        $all = $this->canManageAll($request->user());

        if (! $all) {
            $this->assertScopedManager($request);
        }

        return Inertia::render('Admin/Users', [
            'canManageAll' => $all,
            'pending' => PendingRegistration::query()
                ->when(! $all, fn ($q) => $q->where('position', self::RESIDENT))
                ->orderBy('requested_at')
                ->get()
                ->map(fn (PendingRegistration $p): array => [
                    'id' => $p->id,
                    'full_name' => $p->full_name,
                    'member_name' => $p->member_name,
                    'member_email' => $p->member_email,
                    'position' => (int) $p->position,
                    // Task 6 / Decision A — the client's deleted `fmt()` helper called
                    // `new Date(iso).toLocaleString()` on this value, which renders in the
                    // BROWSER's own locale and timezone rather than the instance's. Formatting
                    // it here (an already-typed Carbon attribute, not an ambiguous string —
                    // the same display-formatting pattern Task 5 used for
                    // signed_off_at/reopened_at) removes that class of "the timestamp is wrong
                    // on my laptop" bug entirely.
                    'requested_at' => $p->requested_at?->format('Y-m-d H:i'),
                ]),
            'users' => User::query()
                ->join('people', 'people.id', '=', 'users.person_id')
                ->when(! $all, fn ($q) => $q->where('people.position', self::RESIDENT))
                ->orderBy('people.full_name')
                ->select('users.*')
                ->get()
                ->map(fn (User $u): array => [
                    'id' => $u->id,
                    'full_name' => $u->full_name,
                    'member_name' => $u->member_name,
                    'member_email' => $u->member_email,
                    'position' => (int) $u->position,
                    'active' => (bool) $u->active,
                    'has_two_factor' => $u->hasTwoFactorEnabled(),
                    // The leaver control, made visible. The owner's chosen process is a
                    // periodic review of active accounts (docs/PDPL-PACK.md §6) — and that
                    // review is only as good as what this screen shows. `last_login_at` has
                    // been recorded since July and read by nothing until now, which meant an
                    // account belonging to someone who left six months ago looked exactly
                    // like one in daily use.
                    'last_login_at' => $u->last_login_at?->format('Y-m-d'),
                    'dormant_days' => $u->last_login_at?->diffInDays(now()),
                ]),
            'positions' => Position::orderBy('id')->get(['id', 'name']),

            // Invitations are how accounts are created now. A Chief Resident sees only the
            // ones they could act on, matching how `pending` and `users` are scoped above.
            'invitations' => collect(InvitationController::openInvitations($request->user()))
                ->when(! $all, fn ($c) => $c->where('position', self::RESIDENT))
                ->values()
                ->all(),
            'invitablePositions' => $all
                ? InvitationController::OFFERABLE
                : [self::RESIDENT => InvitationController::OFFERABLE[self::RESIDENT]],
        ]);
    }

    /**
     * Approve one pending registration: create an ACTIVE `users` row copying the profile
     * fields and the already-hashed password verbatim, then consume the pending row.
     * Uniqueness is RE-validated against `users` at approval time — a user created since the
     * registration could collide (422, never a DB-constraint crash).
     */
    public function approve(Request $request, PendingRegistration $pending): RedirectResponse
    {
        $this->authorizeTarget($request, (int) $pending->position);
        $this->assertStillUnique($pending);

        // The verification step existed and was simply not wired into the decision that
        // depends on it: docs/COMPLIANCE.md claimed "email confirmed before an account is
        // activated" while approve() never checked. With /register open to the internet,
        // that let an unproven identity be approved into full four-unit PHI access.
        if ($pending->email_verified_at === null) {
            AuditLog::record(
                'user_approve_denied_unverified',
                'pending='.$pending->id,
                $request->user()->getKey(),
                $request->ip(),
            );

            return back()->with('error', 'This address has not been confirmed yet. Ask them to use the confirmation link before you approve the account.');
        }

        $userId = DB::transaction(function () use ($pending): int {
            $now = now();

            // Match onto the roster before creating a new person (Task 8 / design §5.2.4): a
            // pending self-registration and an imported/invited roster row can name the same
            // human, and `people.email` is unique, so a blind insert would either 23000-crash
            // when they collide or duplicate the person.
            $person = \App\Models\Person::matchByEmail($pending->member_email);

            $personAttributes = [
                'institution_id' => $pending->institution_id,
                'full_name' => $pending->full_name,
                'position' => (int) $pending->position,
                'email' => \App\Models\Person::normalizeEmail($pending->member_email),
                'active' => true,
                'updated_at' => $now,
            ];

            if ($person === null) {
                $personId = DB::table('people')->insertGetId(
                    $personAttributes + ['external' => false, 'created_at' => $now]
                );
            } else {
                $personId = $person->getKey();
                // Un-trash on the way back in: matchByEmail() finds a soft-deleted person too
                // (re-approving someone who left is a reactivation, never a second human), and a
                // raw query-builder update does not clear deleted_at on its own.
                DB::table('people')->where('id', $personId)->update($personAttributes + ['deleted_at' => null]);
            }

            // Query-builder insert (NOT the Eloquent model): the pending hash must land in
            // `users.password` byte-for-byte — the `hashed` cast would treat a model write as
            // a value to (re)derive, so it is bypassed the way LegacyImport copies members.
            $userId = DB::table('users')->insertGetId([
                'person_id' => $personId,
                'institution_id' => $pending->institution_id,
                'member_name' => $pending->member_name,
                // No 'member_email' key — see InvitationAcceptController::store() for why: the
                // raw column still carries a UNIQUE index that nothing keeps in step with
                // `people.email` once a profile edit moves the address on, and approving into a
                // freed, later-reassigned address must not 500 on that stale index.
                'password' => $pending->getRawOriginal('password'),
                'active' => true,
                // Carry the proof across, so the account records that its address WAS
                // confirmed rather than silently losing it at the moment of approval.
                'email_verified_at' => $pending->email_verified_at,
                // Arm the expiry backstop. passwordExpired() returns false whenever this is
                // null, so leaving it unset meant the 90-day rotation never fired for any
                // account created by approval — which is every account except the
                // console-created bootstrap administrator.
                'pass_exp_date' => $now->copy()->addDays(90)->format('Y-m-d'),
                'created_at' => $now,
                'updated_at' => $now,
            ]);

            $pending->delete();

            return $userId;
        });

        AuditLog::record(
            'user_approve',
            'pending='.$pending->id.';user='.$userId,
            $request->user()->getKey(),
            $request->ip(),
        );

        return back()->with('status', 'Registration approved — the account is now active.');
    }

    /**
     * Reject (discard) one pending registration.
     */
    public function reject(Request $request, PendingRegistration $pending): RedirectResponse
    {
        $this->authorizeTarget($request, (int) $pending->position);

        $pending->delete();

        AuditLog::record(
            'user_reject',
            'pending='.$pending->id,
            $request->user()->getKey(),
            $request->ip(),
        );

        return back()->with('status', 'Registration rejected.');
    }

    /**
     * Activate / deactivate an account. Deactivating the last remaining active
     * Administrator is refused (422) — that includes an admin deactivating themselves.
     */
    public function setActive(Request $request, User $user): RedirectResponse
    {
        $this->authorizeTarget($request, (int) $user->position);

        $data = $request->validate([
            'active' => ['required', 'boolean'],
        ]);

        $active = (bool) $data['active'];

        if (! $active && $this->isLastActiveAdministrator($user)) {
            throw ValidationException::withMessages([
                'active' => 'This is the last active Administrator account — it cannot be deactivated.',
            ]);
        }

        $user->update(['active' => $active]);

        // Deactivating a leaver must stop them being NAMED as well as stop them logging in. The
        // two flags answer different questions (P0c) and the admin screen offers one control, so
        // this is where they are kept in step.
        $user->person?->update(['active' => $active]);

        AuditLog::record(
            $active ? 'user_activate' : 'user_deactivate',
            'user='.$user->id,
            $request->user()->getKey(),
            $request->ip(),
        );

        return back()->with('status', $active ? 'Account activated.' : 'Account deactivated.');
    }

    /**
     * Change an account's role. This is the ONLY place position 0 (Administrator) can be
     * granted. Demoting the last remaining active Administrator is refused (422).
     */
    public function setPosition(Request $request, User $user): RedirectResponse
    {
        $data = $request->validate([
            'position' => ['required', 'integer', Rule::in(self::POSITIONS)],
        ]);

        $position = (int) $data['position'];

        if ($position !== 0 && $this->isLastActiveAdministrator($user)) {
            throw ValidationException::withMessages([
                'position' => 'This is the last active Administrator — grant another account the Administrator role first.',
            ]);
        }

        $user->person?->update(['position' => $position]);

        // The role drives the capability set — bust this user's cached resolution at once.
        AccessControl::flush((int) $user->getKey());

        AuditLog::record(
            'user_role_change',
            'user='.$user->id.';position='.$position,
            $request->user()->getKey(),
            $request->ip(),
        );

        return back()->with('status', 'Role updated.');
    }

    /**
     * H / GAP 14 — an admin corrects ANOTHER account's display name / username / email
     * (legacy control.php:406). /profile only ever edits the SESSION identity, so before this a
     * typo'd account needed direct DB access to fix.
     *
     * Role and password are deliberately NOT writable here: role changes run through
     * setPosition() (which owns the last-admin guard and the capability-cache flush) and a
     * password is never admin-settable at all. The explicit field list below is the enforcement —
     * nothing else from the request can reach the model.
     */
    public function updateProfile(Request $request, User $user): RedirectResponse
    {
        // Normalize BEFORE validating: `Rule::unique('people', 'email')` below runs a raw `WHERE
        // email = ?` against the submitted value, but every stored address is normalized
        // (Person::normalizeEmail — lowercased, trimmed) on write. Production MySQL's
        // utf8mb4_unicode_ci collation happens to catch a differently-cased duplicate anyway, but
        // that is a collation accident, not something this check should depend on — a
        // case-sensitive collation would let a duplicate through validation and then hit
        // `people.email`'s unique index as a raw 500. Normalizing the input first makes the
        // comparison correct regardless of collation.
        $request->merge(['member_email' => \App\Models\Person::normalizeEmail($request->input('member_email'))]);

        $data = $request->validate([
            'full_name' => ['required', 'string', 'max:255'],
            'member_name' => [
                'required', 'string', 'max:255',
                // Soft-deleted users still occupy the unique indexes — include them.
                Rule::unique('users', 'member_name')->ignore($user->getKey())->withoutTrashed(),
                Rule::unique('pending_registrations', 'member_name'),
            ],
            'member_email' => [
                'required', 'email', 'max:255',
                Rule::unique('pending_registrations', 'member_email'),
                Rule::unique('people', 'email')->ignore($user->person_id),
            ],
        ]);

        // One email column now, on `people` (owner decision 2026-08-08, overriding the plan's
        // original dual-column draft). `member_email` is a read-through accessor on User, not a
        // column — writing it here would silently do nothing.
        DB::transaction(function () use ($user, $data): void {
            $user->update([
                'member_name' => $data['member_name'],
            ]);

            $user->person?->update([
                'full_name' => $data['full_name'],
                'email' => \App\Models\Person::normalizeEmail($data['member_email']),
            ]);
        });

        // Ids only — a name/email IS the identifying data this edit touches, so it must not be
        // echoed into the audit detail.
        AuditLog::record(
            'user_profile_update',
            'user='.$user->getKey(),
            $request->user()->getKey(),
            $request->ip(),
        );

        return back()->with('status', 'Account details updated.');
    }

    /*
 * USER DELETION WAS REMOVED (owner ruling, 2026-07-19).
 *
 * Legacy PICU-users-delete.php:11 fired a hard `DELETE FROM members`; the re-platform softened
 * that to a soft delete, and this ruling withdraws the capability entirely. `users.id` is the
 * actor foreign key on `audit_log` and is referenced from clinical rows, so retiring an account
 * by ANY removal mechanism degrades the trail that exists to say who did what to a child's
 * record. DEACTIVATION (see `toggleActive`) already does everything deletion was wanted for:
 * the auth path refuses an inactive account and it leaves the roster's active view, while every
 * past action stays attributable to a named, resolvable person.
 *
 * The route was removed with this method, not just the button — see routes/web.php.
 */

    /** Full user management: the unrestricted tier. */
    private function canManageAll(?User $user): bool
    {
        return \App\Support\ManagerScope::canManageAll($user);
    }

    /** The scoped (Chief Resident) tier — must hold users.manage_residents. 403 + audit otherwise. */
    private function assertScopedManager(Request $request): void
    {
        \App\Support\ManagerScope::assertScopedManager($request);
    }

    /**
     * Authorize an action against ONE account/registration: a full manager may act on
     * anyone; a scoped manager on RESIDENTS alone. The denied attempt is audited with the
     * target's position — never a name.
     */
    private function authorizeTarget(Request $request, int $targetPosition): void
    {
        \App\Support\ManagerScope::assertMayTarget($request, $targetPosition);
    }

    /**
     * Re-validate a pending registration's identifiers against the live `users` table at
     * approval time (422 on collision, not a unique-index crash). Soft-deleted users still
     * occupy the `member_name` unique index, so they are included.
     *
     * The email check goes through `people` (P0c/D9, owner decision 2026-08-08): an account
     * having been created for this address SINCE the registration was submitted is the real
     * collision; a ROSTER-ONLY match is not one — that is exactly the merge `approve()` performs
     * above, the same match-or-create discipline as invitations (design §5.2.4).
     */
    private function assertStillUnique(PendingRegistration $pending): void
    {
        $errors = [];

        if (User::withTrashed()->where('member_name', $pending->member_name)->exists()) {
            $errors['member_name'] = 'A user with this username was created after the registration was submitted.';
        }

        $matched = \App\Models\Person::matchByEmail($pending->member_email);

        if ($matched !== null && $matched->hasAccount()) {
            $errors['member_email'] = 'A user with this email was created after the registration was submitted.';
        }

        if ($errors !== []) {
            throw ValidationException::withMessages($errors);
        }
    }

    /**
     * True when the user is an ACTIVE Administrator and no OTHER active Administrator
     * exists — deactivating or demoting them would lock every admin surface forever.
     */
    private function isLastActiveAdministrator(User $user): bool
    {
        if ((int) $user->position !== 0 || ! $user->active) {
            return false;
        }

        // `whereKeyNot` is avoided here: it filters on the unqualified `id` column, which is
        // ambiguous once `people` is joined (both tables have one). `users.id` explicitly.
        return ! User::query()
            ->join('people', 'people.id', '=', 'users.person_id')
            ->where('users.id', '!=', $user->getKey())
            ->where('people.position', 0)
            ->where('users.active', true)
            ->exists();
    }
}
