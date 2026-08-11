<?php

namespace App\Support;

use App\Models\AuditLog;
use App\Models\Capability;
use App\Models\User;
use App\Models\UserCapability;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * AC-04 — the ONE definition of "set an account's per-capability overrides" (P1c-2 Decision F,
 * owner decision 2, 2026-08-10).
 *
 * THE GRANT IS KEYED TO THE ACCOUNT, and this class is not what changes that.
 * `user_capabilities.user_id` stays where it is; `AccessControl::resolve()`, `holdersOf()` and
 * the cache key are untouched. AC-04's *"roles granted per person"* is satisfied by a second
 * SURFACE — Admin -> People grants to the person's linked account — not by a second table and
 * not by a second writer.
 *
 * WHY IT IS ONE BODY RATHER THAN TWO SIMILAR ONES. Setting overrides is not one write, it is
 * four steps that must travel together and that a copy gets three of right:
 *
 *  - The submitted capability ids are validated against the CATALOG. They arrive as array KEYS
 *    of a request payload, which no `Rule::in` on a wildcard can reach, so the check is
 *    explicit — and a surface that forgot it would accept an id for a capability that does not
 *    exist and write a dangling row.
 *  - The diff is computed INSIDE the transaction, against the rows as they are at that moment.
 *    Read it before the transaction and two administrators saving at once each audit a change
 *    the other made.
 *  - `AccessControl::flush()` runs for that account. Without it the resolved set is served from
 *    cache for up to CACHE_TTL and the administrator who just granted a capability watches the
 *    screen disagree with the database.
 *  - The audit rows are written AFTER the commit — `AuditLog::record()` opens its own
 *    transaction and locks the hash-chain tail, so nesting one inside this transaction would
 *    serialise the chain behind it.
 *
 * ONE SUMMARY ROW PLUS ONE ROW PER CHANGED OVERRIDE, because "why did this capability come back
 * / go away?" must be answerable from the trail BY CAPABILITY and not merely by count. A
 * per-user deny is a REVOCATION — the change most likely to be questioned later — and it is
 * named as its own action. Capability keys are configuration, never PHI; no name, address or
 * phone number goes near a detail string.
 *
 * THE WRITER AUDITS, rather than each caller doing it. That is what `$actor`/`$ip` are for:
 * a caller-written row would leave both parameters declared and never read, which is precisely
 * the defect P1c-2 finding 4 recorded against `openInvitations()`'s unused `?User $viewer`. It
 * is `PositionChange::apply()`/`AccountUnbind::apply()`'s shape (write, guard, flush, audit) —
 * appropriate because this is one act per request, not a per-row bulk loop.
 *
 * WHAT THIS CLASS DELIBERATELY DOES NOT DO IS AUTHORIZE. Both surfaces sit in the
 * `cap:access.manage` route group and that is the whole gate; a second, in-controller check
 * against a capability every request reaching those routes already holds could only ever return
 * true, which is the same declared-and-never-used defect in a different costume (the reasoning
 * `routes/web.php` records for `users.unbind`). The People screen RENDERS its panel behind the
 * same capability so the control is not offered where the endpoint would refuse it, but the
 * boundary is the route group's, in one place.
 *
 * Guarded at source level by `tests/Feature/Build/CapabilityWritersAreSingularTest.php`.
 */
final class CapabilityGrant
{
    /**
     * The capability catalog, in the shape both screens render.
     *
     * ONE list offers the choice and validates the submission (D9's rule applied to a
     * permissions picker): `applyForUser()` below checks the submitted ids against the same
     * table this returns, so no surface can offer a capability the writer would refuse or
     * accept one it never offered.
     *
     * @return Collection<int, Capability>
     */
    public static function catalog(): Collection
    {
        return Capability::query()
            ->orderBy('key')
            ->get(['id', 'key', 'label', 'description']);
    }

    /**
     * One account's explicit overrides: capability id => 'grant' | 'deny'.
     *
     * A capability ABSENT from this map is inherited from the role default — which is a third
     * state, not a missing one, and is why the editors offer "Inherit" rather than a checkbox.
     *
     * @return array<int, string>
     */
    public static function overridesFor(User $user): array
    {
        return UserCapability::where('user_id', $user->getKey())
            ->pluck('effect', 'capability_id')
            ->mapWithKeys(static fn ($effect, $id): array => [(int) $id => (string) $effect])
            ->all();
    }

    /**
     * The same overrides for a whole roster at once, keyed by the PERSON the account belongs to.
     *
     * The People screen shows one row per person and must not pay a query per row for it, so
     * this is a single join rather than a loop over `overridesFor()`. `users.deleted_at` is
     * excluded explicitly: a join does not carry the joined model's soft-delete scope, and a
     * retired account's overrides are not this screen's business.
     *
     * @param  iterable<int, int>  $personIds
     * @return array<int, array<int, string>> person id => (capability id => effect)
     */
    public static function overridesByPerson(iterable $personIds): array
    {
        $ids = array_values(array_unique(array_map('intval', is_array($personIds) ? $personIds : iterator_to_array($personIds))));

        if ($ids === []) {
            return [];
        }

        $rows = UserCapability::query()
            ->join('users', 'users.id', '=', 'user_capabilities.user_id')
            ->whereNull('users.deleted_at')
            ->whereIn('users.person_id', $ids)
            ->get(['users.person_id', 'user_capabilities.capability_id', 'user_capabilities.effect']);

        $byPerson = [];

        foreach ($rows as $row) {
            $byPerson[(int) $row->person_id][(int) $row->capability_id] = (string) $row->effect;
        }

        return $byPerson;
    }

    /**
     * Replace one account's explicit overrides with exactly the submitted map — upsert each
     * grant/deny, delete any override omitted from it (omitted == inherit) — then flush that
     * account's resolved set and audit what changed.
     *
     * The parameter is the MAP the whole surface already speaks (`capability id => effect`),
     * not a pair of grant/deny lists: the map is what the request carries, what
     * `overridesFor()` returns and what both editors bind to, and it is the only shape in which
     * "grant AND deny the same capability" is unrepresentable rather than a contradiction a
     * fifth rule would have to resolve.
     *
     * @param  array<array-key, string>  $overrides  capability id => 'grant' | 'deny'
     * @return array<int, string> capability id => 'grant' | 'deny' | 'clear', for every override that CHANGED
     *
     * @throws ValidationException when a submitted key is not a known capability id
     */
    public static function applyForUser(User $user, array $overrides, User $actor, ?string $ip): array
    {
        // Keys are capability ids — validate them against the catalog (never free-form). They
        // are array KEYS, so no wildcard validation rule reaches them.
        $knownCapabilityIds = self::catalog()->map(static fn (Capability $c): int => (int) $c->getKey())->all();

        foreach (array_keys($overrides) as $capabilityId) {
            if (! in_array((int) $capabilityId, $knownCapabilityIds, true)) {
                throw ValidationException::withMessages([
                    'overrides' => 'Unknown capability id in the submitted overrides.',
                ]);
            }
        }

        $userId = (int) $user->getKey();

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

        $actorId = $actor->getKey();

        AuditLog::record(
            'access_user_update',
            'user='.$userId.';overrides='.count($overrides),
            $actorId,
            $ip,
        );

        // One row PER override changed — a per-user grant, a per-user deny (a REVOCATION, the
        // change most likely to be questioned later) and a return-to-inherit are each named.
        $keys = self::capabilityKeys(array_keys($changes));
        $action = ['grant' => 'access_user_grant', 'deny' => 'access_user_deny', 'clear' => 'access_user_override_clear'];

        foreach ($changes as $capabilityId => $effect) {
            AuditLog::record(
                $action[$effect],
                'user='.$userId.';cap='.($keys[$capabilityId] ?? $capabilityId),
                $actorId,
                $ip,
            );
        }

        return $changes;
    }

    /**
     * capability id => dot-notation key, for the given ids. Used to name capabilities in the
     * audit trail (a key is configuration, never PHI).
     *
     * @param  array<int, int>  $capabilityIds
     * @return array<int, string>
     */
    private static function capabilityKeys(array $capabilityIds): array
    {
        if ($capabilityIds === []) {
            return [];
        }

        return Capability::whereIn('id', $capabilityIds)
            ->pluck('key', 'id')
            ->mapWithKeys(static fn ($key, $id): array => [(int) $id => (string) $key])
            ->all();
    }
}
