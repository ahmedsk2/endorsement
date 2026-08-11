<?php

namespace Tests\Feature\Demo;

use App\Support\Demo\DemoLedger;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use InvalidArgumentException;
use Tests\TestCase;

/**
 * `demo_rows` and `App\Support\Demo\DemoLedger` — Munawib ST-05's "removable", P1e Decision F's
 * first third. No demo row is created here: the ledger lands and is proved BEFORE anything writes
 * one, because a creator that ships ahead of its ledger is a creator whose first batch is
 * unremovable forever.
 *
 * PROVENANCE IS A JOIN, NOT A COLUMN. The rejected alternative was a `demo` boolean on every table
 * the seed touches: nine permanent columns for a training feature, on tables including `units`,
 * which carries no provenance column of any kind; a flag anybody can set to false from a database
 * console, after which removal silently misses the row; and — decisively — a column does not
 * ENUMERATE, so proving removal complete would still mean scanning every table. A ledger answers
 * "what did the demo create?" directly, which is the question removal actually asks.
 *
 * A SECOND `institutions` ROW WAS ALSO REJECTED, and by the schema rather than by taste. D11 keeps
 * `institution_id` as provenance and never a query filter, and several UNIQUE indexes are
 * institution-blind by design — `units.code`, `people.email`, `users.member_name` — so a demo unit
 * sharing a real unit's code collides on insert. This table therefore carries no `institution_id`
 * at all, which `test_the_ledger_carries_no_institution_id` pins against the live schema.
 */
class DemoLedgerTest extends TestCase
{
    use RefreshDatabase;

    private const BATCH_A = '11111111-2222-3333-4444-555555555555';

    private const BATCH_B = '66666666-7777-8888-9999-000000000000';

    // --- Recording ----------------------------------------------------------------------------

    public function test_a_row_is_recorded_once_per_table_and_id(): void
    {
        DemoLedger::record('units', 41, self::BATCH_A);

        $this->assertDatabaseHas('demo_rows', [
            'batch_id' => self::BATCH_A,
            'table_name' => 'units',
            'row_id' => 41,
        ]);

        $this->assertSame(1, DB::table('demo_rows')->count());
    }

    /**
     * The same id in two different tables is two different rows — `people` 41 and `units` 41 are
     * unrelated facts, and a ledger keyed on the id alone would refuse the second and lose it.
     */
    public function test_the_same_id_in_two_tables_is_two_ledger_rows(): void
    {
        DemoLedger::record('units', 41, self::BATCH_A);
        DemoLedger::record('people', 41, self::BATCH_A);

        $this->assertSame(2, DB::table('demo_rows')->count());
    }

    /**
     * A REFUSAL, not a database exception surfacing through five layers as a 500. The pair is
     * unique because a row created twice by two batches has no honest answer to "which batch
     * removes it".
     */
    public function test_recording_the_same_row_twice_is_refused(): void
    {
        DemoLedger::record('units', 41, self::BATCH_A);

        $this->expectException(InvalidArgumentException::class);

        try {
            DemoLedger::record('units', 41, self::BATCH_B);
        } finally {
            // Nothing was written by the refused call.
            $this->assertSame(1, DB::table('demo_rows')->count());
        }
    }

    /**
     * The refusal above is a courtesy; THIS is the guarantee. A pre-flight check in PHP is a race
     * and a single writer's promise — the UNIQUE index is what makes a duplicate impossible even
     * for a hand-written repair script.
     */
    public function test_the_unique_pair_is_enforced_by_the_schema_and_not_only_by_the_writer(): void
    {
        DemoLedger::record('units', 41, self::BATCH_A);

        $this->expectException(QueryException::class);

        DB::table('demo_rows')->insert([
            'batch_id' => self::BATCH_B,
            'table_name' => 'units',
            'row_id' => 41,
            'created_at' => now(),
        ]);
    }

    // --- Reading it back ----------------------------------------------------------------------

    /**
     * REMOVAL ORDER IS DEFINED HERE, and it is the only reason the order matters: rows come back
     * newest first, so a child written after its parent is deleted before it and no restrict-on-
     * delete foreign key fires halfway through a removal that has already begun.
     *
     * Ordered by the primary key rather than by `created_at`: several ledger rows are written
     * inside one transaction in the same second, and a timestamp sort would return them in an
     * order the database chose.
     */
    public function test_rows_come_back_grouped_by_batch_and_in_reverse_creation_order(): void
    {
        DemoLedger::record('units', 41, self::BATCH_A);
        DemoLedger::record('people', 7, self::BATCH_A);
        DemoLedger::record('clinics', 3, self::BATCH_A);
        DemoLedger::record('units', 42, self::BATCH_B);

        $this->assertSame([
            ['table' => 'clinics', 'id' => 3],
            ['table' => 'people', 'id' => 7],
            ['table' => 'units', 'id' => 41],
        ], DemoLedger::rowsFor(self::BATCH_A));

        $this->assertSame([
            ['table' => 'units', 'id' => 42],
        ], DemoLedger::rowsFor(self::BATCH_B));

        $this->assertSame([], DemoLedger::rowsFor('a-batch-that-never-ran'));
    }

    public function test_batches_come_back_oldest_first(): void
    {
        $this->assertSame([], DemoLedger::batches());

        DemoLedger::record('units', 41, self::BATCH_A);
        DemoLedger::record('units', 42, self::BATCH_B);
        DemoLedger::record('people', 7, self::BATCH_A);

        $this->assertSame([self::BATCH_A, self::BATCH_B], DemoLedger::batches());
    }

    public function test_has_reports_whether_any_demo_department_exists(): void
    {
        $this->assertFalse(DemoLedger::has());

        DemoLedger::record('units', 41, self::BATCH_A);

        $this->assertTrue(DemoLedger::has());
    }

    // --- Forgetting ---------------------------------------------------------------------------

    /**
     * `forgetBatch()` clears the LEDGER for one batch and nothing else — it deletes no demo row
     * anywhere. Removal (Task 13) deletes the rows the ledger names and then forgets the batch, in
     * that order, so a removal interrupted between the two leaves a ledger naming rows that are
     * already gone rather than rows nobody can find again.
     */
    public function test_forgetting_one_batch_leaves_every_other_batch_alone(): void
    {
        DemoLedger::record('units', 41, self::BATCH_A);
        DemoLedger::record('units', 42, self::BATCH_B);

        DemoLedger::forgetBatch(self::BATCH_A);

        $this->assertSame([], DemoLedger::rowsFor(self::BATCH_A));
        $this->assertSame([self::BATCH_B], DemoLedger::batches());
        $this->assertTrue(DemoLedger::has());

        DemoLedger::forgetBatch(self::BATCH_B);

        $this->assertFalse(DemoLedger::has());
    }

    /** A batch that never ran is not an error — removal of nothing removes nothing. */
    public function test_forgetting_an_unknown_batch_is_a_no_op(): void
    {
        DemoLedger::record('units', 41, self::BATCH_A);

        DemoLedger::forgetBatch('a-batch-that-never-ran');

        $this->assertSame([self::BATCH_A], DemoLedger::batches());
    }

    // --- The schema ---------------------------------------------------------------------------

    /**
     * D11, asserted against the LIVE SCHEMA rather than by reading the migration. There is nothing
     * here to group by an institution even if somebody wanted to: `units` carries no
     * `institution_id` column at all, so a demo department scoped that way could not include a
     * unit — which is most of what a demo department is.
     *
     * The whole column list is compared rather than one absence, because a column added later for
     * a reason nobody wrote down is exactly what this table must not grow.
     */
    public function test_the_ledger_carries_no_institution_id(): void
    {
        $this->assertTrue(Schema::hasTable('demo_rows'));
        $this->assertFalse(Schema::hasColumn('demo_rows', 'institution_id'));

        $columns = Schema::getColumnListing('demo_rows');
        sort($columns);

        $this->assertSame(['batch_id', 'created_at', 'id', 'row_id', 'table_name'], $columns);
    }
}
