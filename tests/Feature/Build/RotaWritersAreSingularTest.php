<?php

namespace Tests\Feature\Build;

use Illuminate\Support\Facades\File;
use Tests\TestCase;

/**
 * P1d Decision F: `App\Support\Rota\RotaAssignment` is the ONLY writer of `master_rota_assignments`
 * and `App\Support\Rota\VacationBooking` is the ONLY writer of `vacations`. Same species as
 * `PersonLevelsHaveOneWriterTest`, `PersonActiveHasOneWriterTest` and `CsvIsTheOnlyReaderWriterTest`
 * — one guard file covering BOTH needle families deliberately, per Decision F: two files asserting
 * the same shape over the same directories would be the duplication the pattern exists to prevent.
 *
 * A rota row (or a vacation row) written by any other path defeats the containment/overlap
 * invariant each model's `booted()` guard enforces — the writer is where "refuse before writing"
 * lives; a second insertion path bypasses it entirely.
 *
 * `tests/` IS NOT SCANNED; `database/` IS, factories and seeders included — the verdict every
 * sibling now states in the same words (made uniform by the 2026-08-12 sweep, ruling 66; the code
 * has always scanned `database/`, since `base_path('database')` contains `factories/`). A factory
 * writing the guarded table IS a second writer of the shape this guard is about; it is merely one
 * whose blast radius stops at the suite, which is why `MasterRotaAssignmentFactory` and
 * `VacationFactory` are NAMED on the allow-list rather than exempted by directory — a reviewable
 * exemption where excluding the directory makes every future factory exempt in advance, invisibly.
 * `tests/` stays out because a guard that named its own fixtures would be unusable.
 *
 * ONE PATH DELETES THESE TABLES WITHOUT THIS GUARD BEING ABLE TO SEE IT, AND IT IS NOT AN
 * OVERSIGHT. `App\Support\Demo\DemoDepartment::remove()` (P1e Task 13) hard-deletes every row the
 * demo department created, which includes its master rota spans and its one week of leave. It
 * deletes by walking `demo_rows` and taking the TABLE NAME FROM THE LEDGER ROW, so the query reads
 * `DB::table($table)` and no substring needle can match it. It CREATES through `RotaAssignment` and
 * `VacationBooking` like everything else — the plan's own test that no writer was bypassed — and
 * this guard stayed green over it with no allow-list entry, which is the result rather than the
 * hope.
 *
 * It is deliberately NOT on ALLOW_LIST: an entry would exempt that file from every needle here
 * while buying no green, blinding this guard at a file that reaches both of these tables. What
 * holds the line is behavioural — `DemoRoundTripTest` compares every table in the live schema
 * around a create-and-remove, so a delete reaching a row the demo did not create shows up as a
 * count that did not come back.
 */
class RotaWritersAreSingularTest extends TestCase
{
    /** Every file allowed to write `master_rota_assignments` or `vacations`, with why. */
    private const ALLOW_LIST = [
        // The one writer of master_rota_assignments (P1d Decision F).
        'app/Support/Rota/RotaAssignment.php',
        // The one writer of vacations (P1d Decision F).
        'app/Support/Rota/VacationBooking.php',
        // Factories populating fixture rows for OTHER tests are not production writers — same
        // carve-out PersonLevelsHaveOneWriterTest makes for PersonLevelFactory.
        'database/factories/MasterRotaAssignmentFactory.php',
        'database/factories/VacationFactory.php',
    ];

    /**
     * THE `$model->update([...])` SHAPE, added after `ClinicWritersAreSingularTest` was found blind
     * to it (P1e-1 adversarial review finding 2) and this guard was probed for the same hole. It
     * had it. MEASURED HERE THE SAME WAY, on a throwaway file under `app/` that rewrote
     * `starts_on`, `ends_on`, `period_id`, `granularity` and `source` across BOTH tables through
     * nothing but `update([...])`: every needle below was absent and this test stayed GREEN. An
     * endpoint could have re-dated every span in the department without touching `RotaAssignment`
     * or `VacationBooking`, and the build would have passed.
     *
     * That is not a hypothetical shape here — `->update([...])` is how `UnitController`,
     * `LevelController` and `HolidayController` each write their own `setActive()`, so it is what a
     * "just nudge this span" endpoint written beside them reaches for first. And it is the shape
     * that most directly defeats what these two writers are FOR: `RotaAssignment` refuses
     * overlapping spans before it writes, and an `update(['starts_on' => ...])` walks one span's
     * start date straight over its neighbour's end with no guard anywhere in the path.
     *
     * MEASURED, per ruling 42, over app/ + database/ + routes/ BEFORE anything was added. Every
     * needle below: ZERO pre-existing matches, in both quote styles. No allow-list entry was
     * bought.
     *
     * WITHDRAWN ON MEASUREMENT, not on taste:
     *   - `->update(['unit_id'` matches `app/Support/UnitMerge.php`, twice — re-pointing
     *     `handovers` and `unit_field_definitions`, neither of them a rota table. That single
     *     incidental match would buy an allow-list entry for the one file that must one day
     *     re-point `master_rota_assignments.unit_id`: `UnitMerge` re-points four unit-owned tables
     *     today and this is not among them. Allow-listing it blinds this guard on precisely the
     *     file the next real offender arrives in — the identical trap
     *     `ClinicWritersAreSingularTest` withdrew `->update(['active'` over.
     *   - `->update(['person_id'` matches the D3 reversal migration, and `person_id` is carried by
     *     `invitations`, `person_levels`, `users` and four fields of `handover_signoffs` besides —
     *     `AccountLinkHasOneWriterTest` states the same objection to the same column name.
     *   - `->update(['institution_id'` matches the institution backfill migration. D11 makes that
     *     column provenance on nearly every table; it identifies no table at all.
     *   - `$span->update(` is ZERO today but was withdrawn anyway: `$span` names a `PersonLevel` in
     *     `Person.php` and `PersonPresenter`, and a rota span under `app/Support/Rota/`. Whichever
     *     of the two guards claims it fires wrongly on the other's writes.
     *   - `$row->update(` — a general-purpose local throughout this codebase, so the needle is
     *     fragile toward FALSE positives rather than cheap. `$assignment` and `$vacation` name
     *     these two models and nothing else in the tree (`MasterRotaController` binds
     *     `Vacation $vacation` by route-model binding, which is exactly why that spelling is the
     *     one a future edit endpoint would use).
     *
     * WHAT THE COLUMN-QUALIFIED HALF CANNOT SEE, per the same observation the clinic guard records:
     * it matches only the FIRST key of the array, so `update(['unit_id' => 2, 'starts_on' => ...])`
     * fires nothing — `unit_id` is withdrawn above. The variable-qualified half is what covers
     * that, whatever column comes first, and the two halves failing for different reasons is why
     * both are kept. The residual gap is a write to `unit_id` or `institution_id`, first in the
     * array, through a variable named neither `$assignment` nor `$vacation` — stated rather than
     * implied.
     *
     * `starts_on` and `ends_on` are also columns of `periods`, so unlike the clinic guard's four
     * these are NOT unique to the tables here. Nothing edits a period's dates today (periods are
     * generated and deleted, never re-dated — `PeriodController` has `index`/`store`/`destroy` and
     * no update path at all), which is why the measurement comes back zero. If a period re-dating
     * screen ever arrives, the answer is to NARROW this needle, not to allow-list
     * `PeriodController` — that controller already refuses a delete while assignments reference the
     * year, which makes it a plausible future home for a real rota write.
     *
     * ---------------------------------------------------------------------------------------
     * THE 2026-08-12 SWEEP (ruling 66). Twenty-two probes — the seventeen writer shapes, with the
     * column-sensitive ones run against both a signature column and a shared one — were planted
     * against this guard, one at a time. TWELVE walked straight through for
     * `master_rota_assignments` and THIRTEEN for `vacations`, which is the second-worst score of
     * the eleven guards and the asymmetry the vacuity twin below now pins. `MasterRotaAssignment::query()
     * ->create(['granularity' => 'day'])` was planted as a real file under `app/` and this test
     * stayed GREEN — the sixth writer shape `DemoRowsAreLedgeredTest` closed a week earlier and
     * design §14 item 26 recorded as still open here. So were `firstOrCreate`, `upsert`,
     * `destroy`, `truncate`, every relation write, and the property-assign that is this
     * codebase's house idiom for a single-column change.
     *
     * MEASURED, per ruling 42, over app/ + database/ + routes/ before anything below was added:
     * every one of the new needles matched ZERO files, in either quote style. No allow-list entry
     * was bought by any of them.
     *
     * WITHDRAWN ON MEASUREMENT, and this is the important one:
     *   - `MasterRotaAssignment::query(` — NINE files (`PeriodController`, `ClinicRoster`,
     *     `DemoDepartment`, `RotaExport`, `RotaFill`, `RotaGrid`, `RotaImport`, `E2eSeeder`, plus
     *     the writer). `Vacation::query(` — FOUR (`RotaExport`, `RotaGrid`, `RotaImport`,
     *     `E2eSeeder`). That is the needle `DemoRowsAreLedgeredTest` could afford, because
     *     `DemoRow::query(` matched its writer and nothing else; here it would buy an entry for
     *     `RotaFill`, `RotaGrid` and `RotaImport` — the three files a second rota writer is most
     *     likely to be born in. Withdrawn, and replaced by the VERB-QUALIFIED form below, which
     *     reaches the same shape at zero cost. The price is stated in the residuals.
     *
     * RESIDUALS. No substring reaches these and none is closed:
     *   - `$assignment->delete()` / `$vacation->delete()` on an already-bound instance. Invisible
     *     to any substring scan, here as in every sibling guard.
     *   - A builder chain with a `where` BETWEEN the model and the write verb —
     *     `MasterRotaAssignment::query()->where(...)->delete()`. The verb-qualified needles below
     *     are one token wide and cannot span it; only the withdrawn `::query(` could, at the cost
     *     above. `PeriodController::destroy()`'s refusal and `RotaAssignmentTest` are what hold
     *     that line behaviourally.
     *   - A MULTI-LINE chain: `MasterRotaAssignment::query()` then `->create(` on the next line.
     *     Same one-token limit.
     *   - The column-qualified `->update(['col'` family matches only a SINGLE-LINE call whose
     *     FIRST array key is that column. Both halves of that were observed on plants, not
     *     reasoned about: this codebase formats create payloads across lines, which is why the
     *     symmetric `->create(['starts_on'` family measured zero for a reason that has nothing to
     *     do with safety and was not bought.
     */
    private const NEEDLES = [
        'MasterRotaAssignment::create(',
        'MasterRotaAssignment::insert(',
        'MasterRotaAssignment::updateOrCreate(',
        // Added by the 2026-08-12 sweep: the static verbs this list never carried. `firstOrCreate`
        // and `upsert` insert rows without ever calling `create`, and `destroy`/`truncate` remove
        // them — a span deleted outside `RotaAssignment` leaves the department a hole nothing
        // refuses, which is the same overlap/containment invariant read from the other side.
        'MasterRotaAssignment::firstOrCreate(',
        'MasterRotaAssignment::upsert(',
        'MasterRotaAssignment::destroy(',
        'MasterRotaAssignment::truncate(',
        'MasterRotaAssignment::find(',
        // THE SIXTH WRITER SHAPE, verb-qualified. `Model::query()->create(` reaches the table
        // through the builder and matches none of the statics above — proved by planting exactly
        // that file and watching this test stay green. Verb-qualified rather than the bare
        // `::query(` the demo guard could afford, for the measurement in the docblock.
        'MasterRotaAssignment::query()->create(',
        'MasterRotaAssignment::query()->insert(',
        'MasterRotaAssignment::query()->firstOrCreate(',
        'MasterRotaAssignment::query()->updateOrCreate(',
        'MasterRotaAssignment::query()->upsert(',
        "DB::table('master_rota_assignments')",
        'DB::table("master_rota_assignments")',
        'Vacation::create(',
        'Vacation::insert(',
        'Vacation::updateOrCreate(',
        'Vacation::firstOrCreate(',
        'Vacation::upsert(',
        'Vacation::destroy(',
        'Vacation::truncate(',
        'Vacation::find(',
        'Vacation::query()->create(',
        'Vacation::query()->insert(',
        'Vacation::query()->firstOrCreate(',
        'Vacation::query()->updateOrCreate(',
        'Vacation::query()->upsert(',
        "DB::table('vacations')",
        'DB::table("vacations")',
        // RELATION WRITES. Neither `Person::assignments()` nor `Person::vacations()` exists today;
        // both are needled anyway, for the reason `ClinicWritersAreSingularTest` needles
        // `->clinics()` — a `Person::vacations()` hasMany is the natural next addition and would
        // be a second writer the moment somebody called `create()` on it. Enumerated per verb
        // rather than taken as the wide `->vacations()->`: the wide form would also name every
        // READ through such a relation, and the remedy for that would be an allow-list entry for
        // `RotaGrid` — blinding this guard at a file that touches both tables.
        '->assignments()->create(',
        '->assignments()->insert(',
        '->assignments()->updateOrCreate(',
        '->assignments()->firstOrCreate(',
        '->assignments()->save(',
        '->assignments()->saveMany(',
        '->assignments()->update(',
        '->assignments()->delete(',
        '->vacations()->create(',
        '->vacations()->insert(',
        '->vacations()->updateOrCreate(',
        '->vacations()->firstOrCreate(',
        '->vacations()->save(',
        '->vacations()->saveMany(',
        '->vacations()->update(',
        '->vacations()->delete(',
        // Column-qualified: catches the idiom whatever the variable is called. Every one of these
        // five columns is written by `RotaAssignment`/`VacationBooking` and by nothing else.
        "->update(['starts_on'",
        '->update(["starts_on"',
        "->update(['ends_on'",
        '->update(["ends_on"',
        "->update(['period_id'",
        '->update(["period_id"',
        "->update(['granularity'",
        '->update(["granularity"',
        "->update(['source'",
        '->update(["source"',
        // Variable-qualified: catches it whatever the COLUMN is, which is the only reach this
        // guard has over `unit_id`, `person_id` and `institution_id` — each of which is another
        // table's column too and so cannot be needled by name.
        '$assignment->update(',
        '$vacation->update(',
        // Property-assign-then-save() ON `unit_id`, added 2026-08-12 with design §14 item 23's fix.
        // This is the shape the fix itself uses (inside the allow-listed writer), and until it
        // existed the guard's reach over `unit_id` was the variable-qualified `update(` needle
        // alone — which cannot see `$assignment->unit_id = $x; $assignment->save();` at all. That is
        // precisely how a merge would re-point a span if it stopped calling
        // `RotaAssignment::repointUnit()`. The TRAILING SPACE is load-bearing, as it is for
        // `->attendee_mode = ` next door: without it this also matches
        // `(int) $assignment->unit_id`, a read `RotaGrid` and `RotaFill` both perform legitimately
        // (measured: 3 such reads across those two files, zero of them writes).
        // MEASURED before adding: one match, `app/Support/Rota/RotaAssignment.php`, which is the
        // control. No allow-list entry bought. Proved by planting a file under `app/` that
        // re-pointed a span this way — named, red, reverted.
        '$assignment->unit_id = ',
        // THE REST OF THE PROPERTY-ASSIGN SHAPE, added by the 2026-08-12 sweep. `unit_id` was
        // needled in isolation last time because item 23's fix happened to touch that one column;
        // the shape was never closed over the columns that DEFINE these two tables, and
        // `$assignment->granularity = 'week';` matched nothing at all. Column-qualified rather
        // than variable-qualified, so it fires whatever the local is called. TRAILING SPACE
        // load-bearing throughout, for the reason `->attendee_mode = ` records next door.
        // MEASURED: ZERO matches each, over app/ + database/ + routes/. `starts_on`/`ends_on`
        // carry the `periods` caveat stated above for their `update([` twins, unchanged and
        // accepted on the same terms; `granularity` and `period_id` belong to these two tables
        // and to nothing else in the schema.
        '->granularity = ',
        '->period_id = ',
        '->starts_on = ',
        '->ends_on = ',
        // The vacations half of `$assignment->unit_id = `. `vacations` carries no `unit_id`
        // deliberately (a vacation overlays whatever unit the rota has a person on), so the
        // shared column a shortcut would reach for here is `person_id` — which
        // `AccountLinkHasOneWriterTest` states cannot be needled bare, hence the variable
        // qualification. ZERO matches.
        '$vacation->person_id = ',
    ];

    public function test_only_the_rota_writers_write_the_rota_tables(): void
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
            'master_rota_assignments must be written only by App\\Support\\Rota\\RotaAssignment and '
            ."vacations only by App\\Support\\Rota\\VacationBooking (P1d Decision F).\n"
            .implode("\n", $offenders));
    }

    /** A stale allow-list is a silently disabled guard. */
    public function test_every_allow_listed_file_still_exists(): void
    {
        foreach (self::ALLOW_LIST as $relative) {
            $this->assertFileExists(base_path($relative), "Allow-listed file {$relative} is gone — prune the list.");
        }
    }

    /**
     * THE VACUITY TWIN (2026-08-12 sweep, ruling 66). Everything above is satisfied by a tree in
     * which nothing writes these tables at all: the offender loop iterates files that mention
     * nothing and `assertSame([], $offenders)` passes. `DemoRowsAreLedgeredTest` has carried this
     * twin since P1e; nine sibling guards did not, so nine guards were green without anything
     * having proved the shapes they scan for exist. So each CONTROL — the writer the whole guard is
     * named after — must actually match one.
     *
     * It is checked PER WRITER rather than over the pair, because a needle list can be perfectly
     * healthy for `master_rota_assignments` and blind for `vacations`, which is exactly the
     * asymmetry the sweep's probe found (`$assignment->unit_id = ` existed; `$vacation`'s
     * equivalent did not).
     */
    public function test_each_writer_really_does_write_its_table(): void
    {
        foreach (['app/Support/Rota/RotaAssignment.php', 'app/Support/Rota/VacationBooking.php'] as $control) {
            $source = (string) File::get(base_path($control));

            $matched = array_values(array_filter(
                self::NEEDLES,
                static fn (string $needle): bool => str_contains($source, $needle),
            ));

            $this->assertNotSame([], $matched,
                $control.' matches none of this guard\'s needles, so the guard is scanning for a '
                .'shape nothing in the tree uses — it would stay green against a second writer '
                .'spelled the way the real one is.');
        }
    }
}
