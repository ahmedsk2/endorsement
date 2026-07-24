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
 * self-registrations and activates/deactivates accounts or changes roles. Every route is
 * `auth` + `cap:users.manage` (admin-only per the seeded matrix — routes/web.php owns the
 * gates), every write is audited PHI-free (ids only), and passwords NEVER appear in props,
 * logs, or audit details.
 *
 * Two hard invariants:
 *  - The pending row's ALREADY-HASHED password is copied VERBATIM through the query builder
 *    (exactly like LegacyImport) so the `hashed` model cast can never re-derive it.
 *  - The LAST remaining active Administrator (position 0) can be neither deactivated nor
 *    demoted (self-lockout guard) — including by themselves.
 */
class UserManagementController extends Controller
{
    /** Valid role ids (0=Administrator .. 4=Resident). Position 0 is grantable ONLY here. */
    private const POSITIONS = [0, 1, 2, 3, 4];

    public function index(): Response
    {
        return Inertia::render('Admin/Users', [
            'pending' => PendingRegistration::query()
                ->orderBy('requested_at')
                ->get()
                ->map(fn (PendingRegistration $p): array => [
                    'id' => $p->id,
                    'full_name' => $p->full_name,
                    'member_name' => $p->member_name,
                    'member_email' => $p->member_email,
                    'position' => (int) $p->position,
                    'requested_at' => $p->requested_at?->toIso8601String(),
                ]),
            'users' => User::query()
                ->orderBy('full_name')
                ->get()
                ->map(fn (User $u): array => [
                    'id' => $u->id,
                    'full_name' => $u->full_name,
                    'member_name' => $u->member_name,
                    'member_email' => $u->member_email,
                    'position' => (int) $u->position,
                    'active' => (bool) $u->active,
                    'has_two_factor' => $u->hasTwoFactorEnabled(),
                ]),
            'positions' => Position::orderBy('id')->get(['id', 'name']),
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
        $this->assertStillUnique($pending);

        $userId = DB::transaction(function () use ($pending): int {
            $now = now();

            // Query-builder insert (NOT the Eloquent model): the pending hash must land in
            // `users.password` byte-for-byte — the `hashed` cast would treat a model write as
            // a value to (re)derive, so it is bypassed the way LegacyImport copies members.
            $userId = DB::table('users')->insertGetId([
                'institution_id' => $pending->institution_id,
                'position' => (int) $pending->position,
                'full_name' => $pending->full_name,
                'member_name' => $pending->member_name,
                'member_email' => $pending->member_email,
                'password' => $pending->getRawOriginal('password'),
                'active' => true,
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

        $user->update(['position' => $position]);

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
                Rule::unique('users', 'member_email')->ignore($user->getKey())->withoutTrashed(),
                Rule::unique('pending_registrations', 'member_email'),
            ],
        ]);

        $user->update([
            'full_name' => $data['full_name'],
            'member_name' => $data['member_name'],
            'member_email' => $data['member_email'],
        ]);

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

    /**
     * Re-validate a pending registration's identifiers against the live `users` table at
     * approval time (422 on collision, not a unique-index crash). Soft-deleted users still
     * occupy the DB unique indexes, so they are included.
     */
    private function assertStillUnique(PendingRegistration $pending): void
    {
        $errors = [];

        if (User::withTrashed()->where('member_name', $pending->member_name)->exists()) {
            $errors['member_name'] = 'A user with this username was created after the registration was submitted.';
        }

        if ($pending->member_email !== null
            && User::withTrashed()->where('member_email', $pending->member_email)->exists()) {
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

        return ! User::query()
            ->whereKeyNot($user->getKey())
            ->where('position', 0)
            ->where('active', true)
            ->exists();
    }
}
