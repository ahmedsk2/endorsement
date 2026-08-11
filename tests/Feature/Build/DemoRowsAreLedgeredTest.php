<?php

namespace Tests\Feature\Build;

use Illuminate\Support\Facades\File;
use Tests\TestCase;

/**
 * P1e Decision F: `App\Support\Demo\DemoLedger` is the ONLY writer of `demo_rows`. Same species as
 * `ClinicWritersAreSingularTest`, `RotaWritersAreSingularTest` and `PersonLevelsHaveOneWriterTest`
 * — a deliberately coarse substring scan whose job is to be a stop-sign a future change trips over,
 * not a static analyser.
 *
 * WHY THIS TABLE NEEDS ONE, AND THE REASON IS UNUSUAL EVEN AMONG ITS SIBLINGS. Every other
 * single-writer guard in this suite protects a constraint the database cannot express. This one
 * protects a claim: that the demo department is REMOVABLE. Removal walks this ledger and deletes
 * what it names, so a demo row created without a ledger entry is not a slightly-wrong row — it is a
 * row that survives removal permanently, in a live instance, indistinguishable from a real one
 * forever. `DemoSeeder` and `E2eSeeder` are what that looks like today: neither marks anything, so
 * neither has a removal path of any kind, and both must throw in production for exactly that reason.
 * A ledger entry written anywhere but here is also a SECOND definition of "what the demo created",
 * which is the failure this codebase keeps paying for.
 *
 * THIS GUARD IS AN EARLY WARNING AND IS HONEST ABOUT BEING COARSE. It is not the proof. The proof
 * is the round trip Task 13 builds — every table's row count snapshotted from
 * `Schema::getTableListing()`, seeded, removed, compared — which catches a row created outside the
 * ledger whatever this scan missed, and which has its own negative control planting exactly such a
 * row. Two mechanisms, layered, and only the second is proof.
 *
 * `tests/` is NOT scanned, for the reason `PersonLevelsHaveOneWriterTest` states: a fixture writing
 * rows for its own test's purposes is not the production integrity surface this closes.
 * `database/` IS scanned, factories and seeders included — they would be named on ALLOW_LIST with a
 * reason rather than exempted by directory (review F9: excluding the directory makes every future
 * factory exempt in advance, invisibly). There is no `DemoRowFactory` and there should not be one:
 * a ledger row is meaningful only beside the row it names.
 */
class DemoRowsAreLedgeredTest extends TestCase
{
    /**
     * Every file allowed to write `demo_rows`, with why.
     *
     * `App\Support\Demo\DemoDepartment` (Tasks 12 and 13) is deliberately ABSENT and must stay so:
     * it CALLS this ledger for every row it creates and every batch it forgets, which is the whole
     * point of the ledger existing. If it ever needs an entry here, the thing to change is
     * `DemoDepartment`, not this list. An allow-list entry for a file that does not exist yet is a
     * stale entry, and `test_every_allow_listed_file_still_exists` below refuses exactly that.
     */
    private const ALLOW_LIST = [
        // The one writer (P1e Decision F). This is the control.
        'app/Support/Demo/DemoLedger.php',
    ];

    /**
     * THE FIVE WRITER SHAPES. Each was proved by planting a writer of exactly that shape in a
     * throwaway file under `app/`, watching this test name it, then reverting — review F8's rule
     * that a needle nobody has seen fail is a comment, and P1c-2's finding that a guard scanning
     * only for `::create(` missed three of the four ways this codebase actually writes a row.
     *
     *  1. STATIC: `DemoRow::create(` / `insert(` / `updateOrCreate(` / `firstOrCreate(` / `upsert(`,
     *     plus `find(`, `destroy(` and `truncate(` for the destructive half — `forgetBatch()` is a
     *     delete, and a second file deleting ledger rows orphans demo rows just as thoroughly as a
     *     second file creating them.
     *  2. QUERY BUILDER: `DB::table('demo_rows')`, both quote styles. This is the shape that
     *     bypasses the model entirely, and the one a "quick cleanup command" reaches for.
     *  3. PROPERTY ASSIGNMENT then `->save()`. `$row->batch_id = $batch;` matches no static needle
     *     at all. The `= ` TRAILING SPACE is load-bearing, exactly as `AccountLinkHasOneWriterTest`
     *     records for `->person_id = `: without it, `->batch_id` also matches a comparison such as
     *     `->batch_id === $batch`, which a future reader of the ledger performs legitimately.
     *  4. RELATION writes. `->demoRows()->create(` and its siblings. No such relation exists today;
     *     it is needled anyway, because a `Unit::demoRows()` or `Person::demoRows()` morph is the
     *     natural next addition and would be a second writer the moment somebody called `create()`
     *     on it.
     *  5. `$model->update([...])` — THE HOUSE IDIOM, and the one three guards in this suite shipped
     *     blind to (P1e-1 adversarial review finding 2). Covered in both halves the clinic guard
     *     settled on: COLUMN-QUALIFIED, which catches the idiom whatever the variable is called,
     *     and VARIABLE-QUALIFIED, which catches it whatever the column is. The two halves fail for
     *     different reasons — the column-qualified form matches only the array's FIRST key, so
     *     `update(['batch_id' => …, 'row_id' => …])` fires one needle and not the other — which is
     *     the property that makes keeping both worth the extra strings.
     *
     * A SIXTH SHAPE WAS FOUND WHILE PROVING THE VACUITY TWIN BELOW, NOT WHILE WRITING THIS LIST.
     * `DemoRow::query()->create(…)` writes the table and matches none of the five above — the twin
     * caught it because the writer was mutated into exactly that spelling and the twin went red
     * with the guard's own offender sweep still green. `DemoRow::query()` is therefore needled as a
     * whole, which also covers `::query()->…->delete()` (the shape `forgetBatch()` itself uses) and
     * every other route from the model to its builder.
     *
     * That needle deliberately matches READS as well, and for this table that is correct rather
     * than over-broad: `demo_rows` is bookkeeping with exactly one legitimate consumer, and a
     * second file reading it is a second answer to "what did the demo create" — the same failure a
     * second writer causes, arrived at from the other side. Every caller asks `DemoLedger`.
     * MEASURED at zero matches outside the writer before it was added. The sibling guards
     * (`ClinicWritersAreSingularTest`, `RotaWritersAreSingularTest`) share the original blind spot;
     * widening them is their tables' scope, not this one's.
     *
     * MEASURED, per ruling 42, over app/ + database/ + routes/ BEFORE anything was added: every
     * needle below matched ZERO files, in either quote style. `demo_rows`, `DemoRow`, `batch_id`,
     * `table_name` and `row_id` name this table and nothing else in the schema — `person_levels`'
     * batch column is `promotion_batch_id`, which `->batch_id = ` does not match. So this list buys
     * no allow-list entry and blinds no file, which is the test the clinic guard's withdrawn
     * `->update(['active'` needle failed.
     */
    private const NEEDLES = [
        'DemoRow::create(',
        'DemoRow::query()',
        'DemoRow::insert(',
        'DemoRow::updateOrCreate(',
        'DemoRow::firstOrCreate(',
        'DemoRow::upsert(',
        'DemoRow::destroy(',
        'DemoRow::truncate(',
        'DemoRow::find(',
        "DB::table('demo_rows')",
        'DB::table("demo_rows")',
        '->demoRows()->create(',
        '->demoRows()->insert(',
        '->demoRows()->updateOrCreate(',
        '->demoRows()->firstOrCreate(',
        '->demoRows()->save(',
        '->demoRows()->saveMany(',
        '->demoRows()->update(',
        '->demoRows()->delete(',
        '->batch_id = ',
        '->table_name = ',
        '->row_id = ',
        // Shape 5, column-qualified: the idiom whatever the variable is called.
        "->update(['batch_id'",
        '->update(["batch_id"',
        "->update(['table_name'",
        '->update(["table_name"',
        "->update(['row_id'",
        '->update(["row_id"',
        // Shape 5, variable-qualified: the idiom whatever the COLUMN is.
        '$demoRow->update(',
        '$ledgerRow->update(',
    ];

    public function test_only_the_demo_ledger_writes_the_ledger_table(): void
    {
        $offenders = [];

        foreach ([app_path(), base_path('database'), base_path('routes')] as $dir) {
            foreach (File::allFiles($dir) as $file) {
                if ($file->getExtension() !== 'php') {
                    continue;
                }

                $relative = str_replace('\\', '/', str_replace(base_path().DIRECTORY_SEPARATOR, '', $file->getPathname()));

                if (in_array($relative, self::ALLOW_LIST, true)) {
                    continue;
                }

                $contents = (string) File::get($file->getPathname());

                foreach (self::NEEDLES as $needle) {
                    if (str_contains($contents, $needle)) {
                        $offenders[] = $relative.' contains '.$needle;
                    }
                }
            }
        }

        $this->assertSame([], $offenders,
            '`demo_rows` must be written only by App\\Support\\Demo\\DemoLedger (P1e Decision F). A '
            ."ledger entry written elsewhere is a second definition of what the demo created; a demo\n"
            ."row created with no entry at all survives removal forever, in a live instance.\n"
            .implode("\n", $offenders));
    }

    /**
     * A stale allow-list is a silently disabled guard. This one also stays honest the other way:
     * the single entry is the writer itself, so if it were ever renamed without this list following,
     * the guard would name the new writer as an offender rather than pass quietly.
     */
    public function test_every_allow_listed_file_still_exists(): void
    {
        foreach (self::ALLOW_LIST as $relative) {
            $this->assertFileExists(base_path($relative), "Allow-listed file {$relative} is gone — prune the list.");
        }
    }

    /**
     * THE VACUITY TWIN THE NEEDLE LIST CANNOT PROVIDE. Every assertion above is satisfied by a tree
     * containing no ledger at all: the offender loop iterates files that mention nothing, and
     * `assertSame([], $offenders)` passes. Trap 3, four times over in this slice — a guard passing
     * on its first red run has proved nothing until something proves the thing it scans for exists.
     * So the writer must actually contain the shape this guard is built around.
     */
    public function test_the_one_writer_really_does_write_the_ledger_table(): void
    {
        $source = (string) File::get(base_path('app/Support/Demo/DemoLedger.php'));

        $matched = array_values(array_filter(
            self::NEEDLES,
            static fn (string $needle): bool => str_contains($source, $needle),
        ));

        $this->assertNotSame([], $matched,
            'DemoLedger matches none of this guard\'s needles, so the guard is scanning for a '
            .'shape nothing in the tree uses — it would stay green against any second writer '
            .'spelled the way the real one is.');
    }
}
