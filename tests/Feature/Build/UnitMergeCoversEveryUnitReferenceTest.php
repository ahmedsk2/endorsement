<?php

namespace Tests\Feature\Build;

use App\Support\UnitMerge;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Tests\Support\TableCounts;
use Tests\TestCase;

/**
 * ADDING A `unit_id` COLUMN OBLIGES YOU TO ANSWER `UnitMerge`.
 *
 * Design §14 item 23's own diagnosis, turned into a build failure. For four slices a merge covered
 * four of the seven foreign keys pointing at `units` and stranded three — `master_rota_assignments`
 * and `reminder_preferences` from P1d and earlier, `clinics` from P1e — and the worst of them had
 * no repair path at all: a stranded reminder opt-in names a retired unit, `endorsement:remind`
 * iterates ACTIVE units only, and no screen in the product lists or re-points another person's row.
 * Nothing was broken *inside* the writer. What was missing was a cross-cutting obligation nothing
 * checked, exactly the shape of CLAUDE.md's `institution_id`-led-index invariant.
 *
 * DERIVED FROM THE LIVE SCHEMA, NOT FROM THE CONSTANT IT GUARDS — `DemoRemoveTest`'s precedent, and
 * `ReservedUnitCodesTest`'s before it. A hand-written list of foreign keys goes stale the next time
 * somebody writes a migration, and the failure is invisible: no error, no exception, just a table
 * quietly left behind on a unit that no longer appears anywhere. So the real set is introspected and
 * compared against {@see UnitMerge::REFERENCES} in BOTH directions.
 *
 * WHY THIS IS A SEPARATE PROPERTY FROM `DemoReferences::MAP`, which introspects the same table's
 * inbound keys. That map answers *"can removal delete this demo row without taking a real one"*;
 * this one answers *"does a merge move this row or knowingly leave it"*. They will drift apart the
 * moment a `unit_id` column appears on a table the demo never writes, and folding either into the
 * other would make one of the two questions unanswerable.
 *
 * A MAP ENTRY IS A DECISION, NOT DOCUMENTATION. A table whose answer is "a merge deliberately does
 * nothing with this" still belongs in the constant, spelled out — omitting it is precisely the
 * failure being guarded against. There is no such entry today; all seven move.
 *
 * PROVED NON-VACUOUS RATHER THAN ASSUMED (ruling 42's discipline, and the "a guard green on its
 * first run is a comment" rule): with `reminder_preferences.unit_id` deleted from the constant,
 * `test_every_foreign_key_pointing_at_units_is_answered` named it and failed; with a fabricated
 * `nowhere.unit_id` entry added, `test_the_map_names_only_real_foreign_keys` named that and failed.
 * Both were then reverted. The floor assertion below is the third leg: introspection returning
 * nothing would make both sweeps vacuously green while looking identical to a clean tree.
 */
class UnitMergeCoversEveryUnitReferenceTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Every `table.column` in the live schema whose foreign key points at `units`.
     *
     * @return list<string>
     */
    private function inboundUnitForeignKeys(): array
    {
        $found = [];

        foreach (Schema::getTableListing() as $qualified) {
            $table = TableCounts::bare($qualified);

            foreach (Schema::getForeignKeys($table) as $foreignKey) {
                if (TableCounts::bare((string) $foreignKey['foreign_table']) !== 'units') {
                    continue;
                }

                foreach ((array) $foreignKey['columns'] as $column) {
                    $found[] = $table.'.'.$column;
                }
            }
        }

        sort($found);

        return array_values(array_unique($found));
    }

    public function test_every_foreign_key_pointing_at_units_is_answered(): void
    {
        $inbound = $this->inboundUnitForeignKeys();

        // The floor: introspection returning nothing would make this sweep vacuously green.
        $this->assertGreaterThan(4, count($inbound),
            'Foreign-key introspection found almost nothing pointing at `units` — this guard cannot '
            .'be trusted until it does.');

        $unanswered = array_values(array_diff($inbound, array_keys(UnitMerge::REFERENCES)));

        $this->assertSame([], $unanswered,
            "These columns point at `units` and UnitMerge::REFERENCES does not say what a merge does\n"
            ."with them. A row left behind on a retired unit fails silently — there is no error and,\n"
            ."for reminder_preferences, no screen anywhere that can repair one. Add each to the map\n"
            ."with the decision (including \"a merge deliberately leaves this\", if that is the\n"
            ."answer), and cover it in UnitMerge::plan()/::commit() if it moves.\n"
            .implode("\n", $unanswered));
    }

    /** The other direction: an entry naming a key that no longer exists is a check that never fires. */
    public function test_the_map_names_only_real_foreign_keys(): void
    {
        $stale = array_values(array_diff(array_keys(UnitMerge::REFERENCES), $this->inboundUnitForeignKeys()));

        $this->assertSame([], $stale,
            "UnitMerge::REFERENCES names foreign keys the schema no longer has. A stale entry is a\n"
            ."silently disabled guard — prune it.\n".implode("\n", $stale));
    }

    /** Every decision is written down as a sentence, not left as an empty string nobody reads. */
    public function test_every_answer_says_something(): void
    {
        foreach (UnitMerge::REFERENCES as $column => $answer) {
            $this->assertGreaterThan(30, strlen($answer),
                "UnitMerge::REFERENCES[{$column}] does not explain what a merge does with it.");
        }
    }
}
