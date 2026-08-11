<?php

namespace Tests\Feature\Demo;

use App\Models\Institution;
use App\Models\Person;
use App\Support\Demo\DemoDepartment;
use App\Support\Demo\DemoReferences;
use Database\Seeders\AccessControlSeeder;
use Database\Seeders\ReferenceSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Tests\Support\TableCounts;
use Tests\TestCase;

/**
 * THE PROOF (P1e Decision F, Task 13). Everything else about the demo department's removability is
 * an argument; this is the measurement.
 *
 * Four mechanisms are layered behind ST-05's "removable", and only this one is proof:
 *   1. `DemoLedger` is the single writer of the ledger, guarded at source level.
 *   2. `DemoRowsAreLedgeredTest` scans the demo module for a creator that records nothing — an
 *      early warning, honest about being a coarse substring scan.
 *   3. THIS: snapshot every table in the live schema, create, remove, compare the maps WHOLE. A row
 *      created outside the ledger cannot survive it, whatever the scan missed.
 *   4. The negative control at the bottom, which is what makes 3 mean anything. A round-trip test
 *      that has never gone red compares nothing.
 *
 * THE TABLE LIST IS DERIVED, ALWAYS. `Schema::getTableListing()` on every call, never a constant —
 * a hand-written list goes stale on the next migration and the test keeps passing, blind to exactly
 * the table it should have been watching. And the maps are compared WHOLE rather than key by key,
 * because SQLite returns schema-qualified names (`main.units`) and MySQL does not, so a hand-keyed
 * assertion finds nothing on either side and passes forever (observed on this call in Task 10).
 */
class DemoRoundTripTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Every table excluded from the comparison, with the reason it is excluded. A reason per entry
     * is not decoration: `test_the_exclusion_list_holds_only_append_only_or_framework_tables` reads
     * this map and fails on an empty one, and an exclusion nobody justified is how a table the demo
     * actually writes quietly stops being watched.
     *
     * `demo_rows` IS DELIBERATELY ABSENT, and that is a correction to the plan's own text, which
     * listed it. Excluding the ledger would excuse the one failure mode nobody else catches — rows
     * deleted while their ledger entries survive, or the reverse. It returns to its pre-seed count
     * on a complete round trip like every other table, and it is held to that here.
     */
    private const EXCLUSIONS = [
        'audit_log' => 'Append-only and hash-chained. Both the create and the remove append one '
            .'summary row each, deliberately (invariant 12) — that is the trail working, not leakage, '
            .'and a hash chain cannot be rewound to make a count match.',
        'migrations' => 'The migrator\'s own bookkeeping. Nothing in the application writes it, and '
            .'`RefreshDatabase` fills it before either snapshot is taken.',
        'applied_role_defaults' => 'AccessControlSeeder\'s append-only ledger of "this seed step '
            .'already ran" — the reason a revoked capability default is never re-asserted. Never '
            .'written by any demo path.',
        'cache' => 'The database cache store (CACHE_STORE=database). Any read path may populate or '
            .'evict a row, so its count is a property of what else ran, not of the demo.',
        'cache_locks' => 'Same store, same reason — lock rows appear and vanish under any cached read.',
        'sessions' => 'The database session driver. A row appears the moment anything touches a '
            .'session, which the console path does not and the HTTP path does.',
        'jobs' => 'The database queue. A queued notification lands here and is consumed '
            .'asynchronously, so its count is not a property of the operation under test.',
        'job_batches' => 'Same queue, same reason.',
        'failed_jobs' => 'Same queue, same reason.',
        'password_reset_tokens' => 'Laravel\'s own password broker table, keyed by email rather than '
            .'by id, written only by a reset request.',
    ];

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(ReferenceSeeder::class);
        $this->seed(AccessControlSeeder::class);
    }

    /**
     * The whole-schema snapshot minus the excluded tables.
     *
     * @return array<string, int>
     */
    private function watchedCounts(): array
    {
        $counts = [];

        foreach (TableCounts::snapshot() as $qualified => $count) {
            if (! array_key_exists(TableCounts::bare($qualified), self::EXCLUSIONS)) {
                $counts[$qualified] = $count;
            }
        }

        return $counts;
    }

    // --- The proof -------------------------------------------------------------------------------

    public function test_removal_returns_every_table_to_its_pre_seed_row_count(): void
    {
        $before = $this->watchedCounts();

        // A floor, so the comparison below cannot be green over an empty schema.
        $this->assertGreaterThan(20, count($before), 'The schema snapshot is implausibly small.');

        $created = DemoDepartment::create();

        $this->assertNotSame($before, $this->watchedCounts(), 'The create must actually change something.');

        DemoDepartment::remove($created['batch']);

        $this->assertSame($before, $this->watchedCounts(),
            'A table that did not return to its pre-seed count holds a row the demo created and '
            .'removal could not reach — which, on a live instance, is a row indistinguishable from '
            .'a real one forever.');
    }

    /** And it holds a second time round, which is what "removable" has to mean for a training tool. */
    public function test_the_round_trip_holds_twice(): void
    {
        $before = $this->watchedCounts();

        for ($run = 0; $run < 2; $run++) {
            $created = DemoDepartment::create();
            DemoDepartment::remove($created['batch']);
        }

        $this->assertSame($before, $this->watchedCounts());
    }

    // --- The exclusion list ----------------------------------------------------------------------

    public function test_the_exclusion_list_holds_only_append_only_or_framework_tables(): void
    {
        $this->assertNotSame([], self::EXCLUSIONS);

        $listing = array_map(
            static fn (string $qualified): string => TableCounts::bare($qualified),
            Schema::getTableListing(),
        );

        $problems = [];

        foreach (self::EXCLUSIONS as $table => $reason) {
            if (trim($reason) === '') {
                $problems[] = $table.' is excluded with no stated reason';
            }

            if (! in_array($table, $listing, true)) {
                $problems[] = $table.' is excluded but is not in the schema — prune the list';
            }

            // THE ONE THAT MATTERS: a table the demo writes may never be excused from the
            // comparison, or the proof is proving nothing about the rows that actually exist.
            if (array_key_exists($table, DemoReferences::MAP)) {
                $problems[] = $table.' is a table DemoDepartment writes and must not be excluded';
            }
        }

        $this->assertSame([], $problems, implode("\n", $problems));
    }

    /**
     * The exclusion list must not quietly swallow a table the demo grows. Measured rather than
     * argued: create, and assert every table whose count moved is one the comparison WATCHES,
     * apart from the audit row the operation is required to write.
     */
    public function test_nothing_the_demo_creates_lands_in_an_excluded_table(): void
    {
        $before = TableCounts::snapshot();

        DemoDepartment::create();

        $moved = array_keys(TableCounts::delta($before, TableCounts::snapshot()));
        $excused = array_values(array_intersect($moved, array_keys(self::EXCLUSIONS)));

        $this->assertSame(['audit_log'], $excused,
            'The demo grew an excluded table other than the one summary audit row it is supposed to '
            .'write, which means the round trip is blind to part of what it creates.');
    }

    // --- The negative control --------------------------------------------------------------------

    /**
     * THE TEST THAT MAKES EVERY OTHER ASSERTION IN THIS FILE MEAN SOMETHING.
     *
     * A row is created and NOT recorded — exactly what a `create()` missing one
     * `DemoLedger::record()` call leaves behind. Removal then runs to completion (the stray row
     * references nothing demo, so the pre-flight has no reason to refuse) and the count comparison
     * is the only thing left that can notice. It must fail, and it must NAME the table.
     *
     * WHY NOT A THROWAWAY SUBCLASS, which is what the plan sketched: `DemoDepartment` is `final`,
     * in this codebase's house style for support classes, and un-finalising a production class so a
     * test can subclass it weakens the class to suit the test. Planting the row beside the creator
     * reproduces the same state — an unledgered row in a table the demo writes — without that
     * trade. The complementary half, mutating `create()` itself to drop a `record()` call, was run
     * by hand and watched failing at the same assertion (recorded in the plan's amendments).
     */
    public function test_a_row_created_outside_the_ledger_makes_the_round_trip_fail(): void
    {
        $before = $this->watchedCounts();

        $created = DemoDepartment::create();

        Person::create([
            'institution_id' => Institution::query()->value('id'),
            'full_name' => DemoDepartment::LABEL_PREFIX.'Resident Four',
            'short_name' => 'Demo-R4',
            'position' => 4,
            'active' => true,
        ]);

        DemoDepartment::remove($created['batch']);

        $after = $this->watchedCounts();

        $this->assertNotSame($before, $after,
            'The round trip did not notice a row created outside the ledger, which means it cannot '
            .'notice one at all.');

        $moved = array_keys(TableCounts::delta($before, $after));

        $this->assertSame(['people'], $moved, 'And it names the table holding the survivor.');
    }
}
