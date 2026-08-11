<?php

namespace Tests\Support;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * ONE row-count snapshot over the WHOLE schema, shared by every test that proves something about
 * what a write path did or did not touch.
 *
 * WHY IT IS DERIVED AND NOT WRITTEN DOWN. A hand-written table list goes stale on the very next
 * migration and the test keeps passing — silently blind to precisely the table it should have been
 * watching. `Schema::getTableListing()` is the only source, and it is asked afresh on every call.
 *
 * WHY THE MAP IS COMPARED WHOLE. SQLite returns SCHEMA-QUALIFIED names (`main.units`); MySQL does
 * not. So an assertion written against a hand-keyed `'units'` finds nothing on either side and
 * passes forever — observed on this exact call in P1e Task 10, which is why {@see qualify()} exists
 * for the rare case a test really does need to name one table, and why every other caller compares
 * `snapshot()` against `snapshot()`.
 *
 * Counts are taken through the QUERY BUILDER, not through Eloquent: `people`, `users`, `handovers`
 * and `handover_signoffs` soft-delete, and a tombstoned row is still a row occupying its table's
 * unique indexes. A count that silently skipped them would call a removal complete while the rows
 * were still there.
 *
 * It lives in `tests/Support/` beside `SourceScanner`, which was extracted for the same reason in
 * P1e Task 6: two copies of one helper are two definitions of one fact, and they drift the first
 * time one of them learns about a new engine's naming.
 */
final class TableCounts
{
    /**
     * Every table in the live schema, keyed by the name the database itself gives.
     *
     * @return array<string, int>
     */
    public static function snapshot(): array
    {
        $counts = [];

        foreach (Schema::getTableListing() as $qualified) {
            $counts[$qualified] = DB::table(self::bare($qualified))->count();
        }

        ksort($counts);

        return $counts;
    }

    /** `main.units` → `units`. A no-op on an engine that does not qualify. */
    public static function bare(string $qualified): string
    {
        return str_contains($qualified, '.')
            ? substr($qualified, (int) strrpos($qualified, '.') + 1)
            : $qualified;
    }

    /**
     * The key {@see snapshot()} uses for one bare table name, resolved from the live listing rather
     * than assumed — so a test naming a single table works on both engines.
     */
    public static function qualify(string $bare): string
    {
        foreach (Schema::getTableListing() as $qualified) {
            if (self::bare($qualified) === $bare) {
                return $qualified;
            }
        }

        return $bare;
    }

    /**
     * The per-table difference between two snapshots, keyed by BARE table name, with unchanged
     * tables dropped.
     *
     * @param  array<string, int>  $before
     * @param  array<string, int>  $after
     * @return array<string, int>
     */
    public static function delta(array $before, array $after): array
    {
        $delta = [];

        foreach ($after as $qualified => $count) {
            $grew = $count - ($before[$qualified] ?? 0);

            if ($grew !== 0) {
                $delta[self::bare($qualified)] = $grew;
            }
        }

        ksort($delta);

        return $delta;
    }
}
