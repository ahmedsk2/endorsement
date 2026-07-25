<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Models\Capability;
use App\Models\Position;
use App\Models\RoleCapability;
use App\Models\User;
use App\Models\UserCapability;
use App\Support\AccessControl;
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
 */
class AccessControlController extends Controller
{
    /** Valid role ids (0=Administrator .. 4=Resident). */
    private const POSITIONS = [0, 2, 3, 4, 5];

    public function index(Request $request): Response
    {
        $capabilities = Capability::query()
            ->orderBy('key')
            ->get(['id', 'key', 'label', 'description']);

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
            'users' => User::query()
                ->orderBy('full_name')
                ->get(['id', 'member_name', 'full_name', 'position']),
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
     */
    public function updateUser(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'user_id' => ['required', 'integer', 'exists:users,id'],
            'overrides' => ['present', 'array'],
            'overrides.*' => [Rule::in(['grant', 'deny'])],
        ]);

        $overrides = $data['overrides'];

        // Keys are capability ids — validate them against the catalog (never free-form).
        $knownCapabilityIds = Capability::pluck('id')->map(static fn ($id): int => (int) $id)->all();
        foreach (array_keys($overrides) as $capabilityId) {
            if (! in_array((int) $capabilityId, $knownCapabilityIds, true)) {
                throw ValidationException::withMessages([
                    'overrides' => 'Unknown capability id in the submitted overrides.',
                ]);
            }
        }

        $userId = (int) $data['user_id'];

        $changes = DB::transaction(function () use ($userId, $overrides): array {
            $keepIds = array_map('intval', array_keys($overrides));

            $before = UserCapability::where('user_id', $userId)
                ->pluck('effect', 'capability_id')
                ->mapWithKeys(static fn ($effect, $id): array => [(int) $id => (string) $effect])
                ->all();

            // Delete overrides that are no longer present (omitted == inherit).
            UserCapability::where('user_id', $userId)
                ->when($keepIds !== [], fn ($query) => $query->whereNotIn('capability_id', $keepIds))
                ->delete();

            foreach ($overrides as $capabilityId => $effect) {
                UserCapability::updateOrCreate(
                    ['user_id' => $userId, 'capability_id' => (int) $capabilityId],
                    ['effect' => $effect],
                );
            }

            // capability_id => 'grant' | 'deny' | 'clear' for every override that CHANGED.
            $changes = [];
            foreach ($overrides as $capabilityId => $effect) {
                $capabilityId = (int) $capabilityId;
                if (($before[$capabilityId] ?? null) !== $effect) {
                    $changes[$capabilityId] = $effect;
                }
            }
            foreach ($before as $capabilityId => $effect) {
                if (! in_array($capabilityId, $keepIds, true)) {
                    $changes[$capabilityId] = 'clear';
                }
            }

            return $changes;
        });

        AccessControl::flush($userId);

        $actorId = $request->user()->getKey();
        $ip = $request->ip();

        AuditLog::record(
            'access_user_update',
            'user='.$userId.';overrides='.count($overrides),
            $actorId,
            $ip,
        );

        // One row PER override changed — a per-user grant, a per-user deny (a REVOCATION, the
        // change most likely to be questioned later) and a return-to-inherit are each named.
        $keys = $this->capabilityKeys(array_keys($changes));
        $action = ['grant' => 'access_user_grant', 'deny' => 'access_user_deny', 'clear' => 'access_user_override_clear'];

        foreach ($changes as $capabilityId => $effect) {
            AuditLog::record(
                $action[$effect],
                'user='.$userId.';cap='.($keys[$capabilityId] ?? $capabilityId),
                $actorId,
                $ip,
            );
        }

        return back()->with('status', 'User overrides updated.');
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
        if ($userId === null) {
            return null;
        }

        $user = User::find($userId);
        if ($user === null) {
            return null;
        }

        $overrides = UserCapability::where('user_id', $user->getKey())
            ->pluck('effect', 'capability_id')
            ->map(static fn ($effect): string => (string) $effect)
            ->all();

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
