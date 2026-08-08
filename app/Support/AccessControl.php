<?php

namespace App\Support;

use App\Models\User;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * Data-driven access-control resolver.
 *
 * Effective(user) = the capability keys granted to the user's `position`
 * (role_capabilities x capabilities), PLUS any per-user `grant` override,
 * MINUS any per-user `deny` override. Deny always wins.
 *
 * Results are cached per user (key includes the user id) and MUST be busted via flush()
 * from every access-control write (role_capabilities / user_capabilities changes). An
 * unknown capability key resolves to "denied".
 */
class AccessControl
{
    /**
     * Cache lifetime for a resolved capability set (seconds). Writes call flush(), so this
     * only bounds staleness across processes that never touched the write path.
     */
    private const CACHE_TTL = 600;

    /**
     * Monotonic generation counter. The per-user cache key embeds its current value, so
     * incrementing it invalidates EVERY per-user access-control entry at once — without a
     * global Cache::flush() that would also wipe unrelated cache state (login/2FA rate
     * limiters, etc.). Never expires.
     */
    private const GENERATION_KEY = 'access_control.generation';

    /**
     * The sorted, unique set of capability KEYS the user effectively holds.
     *
     * @return array<int, string>
     */
    public static function capabilitiesFor(User $user): array
    {
        return Cache::remember(
            self::cacheKey((int) $user->getKey()),
            self::CACHE_TTL,
            static fn (): array => self::resolve($user),
        );
    }

    /**
     * Whether the user effectively holds a single capability key.
     */
    public static function allows(User $user, string $capabilityKey): bool
    {
        return in_array($capabilityKey, self::capabilitiesFor($user), true);
    }

    /**
     * The ACTIVE users who effectively hold a capability, ordered by name.
     *
     * This is `capabilitiesFor()` inverted, and it exists because a refusal that names who to
     * contact must name people who can ACTUALLY act. Naming a hard-coded role is a half-truth the
     * moment a capability becomes grantable: it sends a ward to an administrator who was denied the
     * capability, and hides the consultant down the corridor who was granted it.
     *
     * The resolution rules are the same three as resolve(), in the same order — role default, then
     * per-user grant, then per-user deny (deny wins). INACTIVE accounts are excluded: they cannot
     * log in, so advertising them is a dead end.
     *
     * Deliberately NOT cached. It is read on a refusal path, where being right matters more than
     * being fast, and a stale contact list is exactly the failure this method exists to prevent.
     *
     * @return list<User>
     */
    public static function holdersOf(string $capabilityKey): array
    {
        $capabilityId = DB::table('capabilities')->where('key', $capabilityKey)->value('id');

        if ($capabilityId === null) {
            // An unknown capability key resolves to "denied" everywhere else; nobody holds it.
            return [];
        }

        $positions = DB::table('role_capabilities')
            ->where('capability_id', $capabilityId)
            ->pluck('position')
            ->all();

        $granted = DB::table('user_capabilities')
            ->where('capability_id', $capabilityId)
            ->where('effect', 'grant')
            ->pluck('user_id')
            ->all();

        $denied = DB::table('user_capabilities')
            ->where('capability_id', $capabilityId)
            ->where('effect', 'deny')
            ->pluck('user_id')
            ->all();

        // `people` carries `position` and `full_name` since P0c, so the inverse of
        // capabilitiesFor() joins it. `select('users.*')` is mandatory: without it the join
        // clobbers `id` with people.id and every caller gets the wrong key.
        return User::query()
            ->join('people', 'people.id', '=', 'users.person_id')
            ->whereNull('people.deleted_at')
            ->where('users.active', true)
            ->where(function ($q) use ($positions, $granted): void {
                // A role default OR an explicit per-user grant is enough to hold it.
                $q->whereIn('people.position', $positions === [] ? [-1] : $positions)
                    ->orWhereIn('users.id', $granted === [] ? [-1] : $granted);
            })
            ->when($denied !== [], fn ($q) => $q->whereNotIn('users.id', $denied))
            ->orderBy('people.full_name')
            ->select('users.*')
            ->get()
            ->all();
    }

    /**
     * Bust the cached capability set for one user, or (with no id) for every user.
     *
     * Call with a user id after a user_capabilities write; call with no argument after a
     * role_capabilities or capabilities-catalog write (that affects every user of a role).
     * The no-id path bumps the generation counter (invalidating all per-user entries) rather
     * than calling Cache::flush(), so unrelated cache state (rate limiters, etc.) is preserved.
     */
    public static function flush(?int $userId = null): void
    {
        if ($userId !== null) {
            Cache::forget(self::cacheKey($userId));

            return;
        }

        // Seed the counter first so increment() works on stores whose increment is a no-op on
        // a missing key (e.g. the database store), then bump it.
        Cache::add(self::GENERATION_KEY, 0);
        Cache::increment(self::GENERATION_KEY);
    }

    /**
     * Resolve the effective key set from the database (no caching here).
     *
     * @return array<int, string>
     */
    private static function resolve(User $user): array
    {
        // Role defaults: capability keys granted to this position.
        $keys = [];
        $roleKeys = DB::table('role_capabilities')
            ->join('capabilities', 'capabilities.id', '=', 'role_capabilities.capability_id')
            ->where('role_capabilities.position', $user->position)
            ->pluck('capabilities.key');

        foreach ($roleKeys as $key) {
            $keys[$key] = true;
        }

        // Per-user overrides. Apply grants first, then denies, so deny wins unconditionally
        // (the unique(user_id, capability_id) constraint already permits at most one row per
        // capability, but the two-pass order makes the "deny wins" invariant explicit).
        $overrides = DB::table('user_capabilities')
            ->join('capabilities', 'capabilities.id', '=', 'user_capabilities.capability_id')
            ->where('user_capabilities.user_id', $user->getKey())
            ->get(['capabilities.key', 'user_capabilities.effect']);

        foreach ($overrides as $override) {
            if ($override->effect === 'grant') {
                $keys[$override->key] = true;
            }
        }

        foreach ($overrides as $override) {
            if ($override->effect === 'deny') {
                unset($keys[$override->key]);
            }
        }

        $result = array_keys($keys);
        sort($result);

        return $result;
    }

    private static function cacheKey(int $userId): string
    {
        $generation = (int) Cache::get(self::GENERATION_KEY, 0);

        return "access_control.caps.v{$generation}.user.{$userId}";
    }
}
