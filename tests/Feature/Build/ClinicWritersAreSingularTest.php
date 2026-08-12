<?php

namespace Tests\Feature\Build;

use Illuminate\Support\Facades\File;
use Tests\TestCase;

/**
 * P1e Decision B: `App\Support\Clinics\ClinicWriter` is the ONLY writer of `clinics` and
 * `clinic_attendees`. Same species as `RotaWritersAreSingularTest`,
 * `PersonLevelsHaveOneWriterTest` and `InvitationWritersAreSingularTest` — a deliberately coarse
 * substring scan whose job is to be a stop-sign a future change trips over, not a static analyser.
 *
 * WHY THIS TABLE PAIR NEEDS ONE, and it is not the usual "keep the audit honest" reason. Three
 * constraints on `clinic_attendees` are NOT expressible in either engine this schema runs on: a row
 * must name EXACTLY ONE of `level_id`/`person_id`; the same level (or person) must not appear twice
 * in one clinic; and the set must be homogeneous with `clinics.attendee_mode`. A UNIQUE index over
 * nullable columns enforces none of them — NULLs compare distinct on both SQLite and MySQL 8.4 —
 * which is the identical situation `2026_08_14_120002`'s docblock records for `person_levels`'
 * overlap rule ("the guarantee lives in `App\Support\LevelAssignment`, which
 * `PersonLevelsHaveOneWriterTest` proves is the only writer"). So this guard is not defence in
 * depth over a database constraint. It is the only thing behind the constraint.
 *
 * `tests/` is NOT scanned, for the reason `PersonLevelsHaveOneWriterTest` states: a fixture seeding
 * rows for its own test's purposes is not the production integrity surface this closes. `database/`
 * IS scanned, factories and seeders included — they are named on ALLOW_LIST with a reason rather
 * than exempted by directory (review F9: excluding the directory makes every future factory exempt
 * in advance, invisibly).
 *
 * `app/Support/Demo/DemoDepartment.php` WILL JOIN THE ALLOW-LIST at P1e-2 Task 12, and should: it
 * is a ledgered creator of a removable demo department, not a second definition of the clinic
 * rules — it calls `ClinicWriter` for every rule and needs the entry only for the one hard delete
 * `remove()` performs, which is ledger-scoped and cannot reach a row it did not create. It is
 * deliberately absent today, because an allow-list entry for a file that does not exist is a stale
 * entry, and `test_every_allow_listed_file_still_exists` below exists to refuse exactly that.
 *
 * ONE PATH DELETES THESE TABLES WITHOUT THIS GUARD BEING ABLE TO SEE IT, AND IT IS NOT AN
 * OVERSIGHT. `App\Support\Demo\DemoDepartment::remove()` (P1e Task 13) hard-deletes every row the
 * demo department created, including its clinic and that clinic's attendee rules — the only path in
 * the product that hard-deletes a `clinics` row at all, which the plan's invariant 7 sanctions
 * explicitly ("there is no delete path, not here and not on the controller"). It deletes by walking
 * `demo_rows` and taking the TABLE NAME FROM THE LEDGER ROW, so the query reads `DB::table($table)`
 * and no substring needle can match it.
 *
 * It is deliberately NOT on ALLOW_LIST. An entry would exempt that file from every needle here
 * while buying no green — it matches none of them either way — and it would blind this guard at a
 * file that legitimately reaches all eight of the demo's tables. What holds the line instead is
 * behavioural and stronger than a grep: `DemoRoundTripTest` snapshots every table in the live
 * schema around a create-and-remove and compares the maps whole, so a delete that reached a row the
 * demo did not create shows up as a count that did not come back.
 */
class ClinicWritersAreSingularTest extends TestCase
{
    /**
     * Every file allowed to write `clinics` or `clinic_attendees`, with why.
     *
     * There is deliberately NO `test_every_allow_listed_file_still_matches_a_needle` twin here,
     * unlike `CalendarWritersFlushTest` and `InstitutionProvenanceTest`. Those two allow-list
     * INCIDENTAL MATCHES, so an entry matching nothing is definitionally stale. This one
     * allow-lists WRITERS, and `ClinicFactory` is a real writer of `clinics` that the substring
     * scan cannot see at all — `Clinic::factory()->create()` inserts through `Factory::create()`,
     * which appears nowhere in the factory's own source. Demanding a needle match would force
     * either a fake one into the factory or the entry out of the list, and the entry is the honest
     * record of what may write. `RotaWritersAreSingularTest` and `PersonLevelsHaveOneWriterTest`
     * both allow-list their factories on the same silent basis.
     */
    private const ALLOW_LIST = [
        // The one writer of both tables (P1e Decision B). This is the control.
        'app/Support/Clinics/ClinicWriter.php',
        // A factory populating fixture clinics for OTHER tests is not a production writer — the
        // same carve-out `PersonLevelsHaveOneWriterTest` makes for `PersonLevelFactory` and
        // `RotaWritersAreSingularTest` for `MasterRotaAssignmentFactory`. It writes `clinics` ONLY
        // and never `clinic_attendees`, so none of the three unenforceable constraints above is
        // reachable through it — stated in its own docblock too, not only here.
        'database/factories/ClinicFactory.php',
    ];

    /**
     * THE FIVE WRITER SHAPES. Each was proved by planting a writer of exactly that shape in a
     * throwaway file under `app/`, watching this test name it, then reverting — review F8's rule
     * that a needle nobody has seen fail is a comment, and P1c-2's finding that a guard scanning
     * only for `::create(` missed three of the four ways this codebase actually writes a row.
     *
     *  1. STATIC: `Model::create(` / `insert(` / `updateOrCreate(` / `firstOrCreate(` / `upsert(`.
     *  2. QUERY BUILDER: `DB::table('clinics')` / `DB::table('clinic_attendees')`, both quote
     *     styles.
     *  3. PROPERTY ASSIGNMENT then `->save()`. `$clinic->attendee_mode = 'named';` matches no
     *     static needle at all, and it is the shape a "just flip the mode" edit reaches for first.
     *     The `= ` TRAILING SPACE is load-bearing, exactly as `AccountLinkHasOneWriterTest` records
     *     for `->person_id = `: without it, `->attendee_mode` also matches
     *     `->attendee_mode === Clinic::MODE_NAMED`, a read `ClinicRoster` performs legitimately.
     *  4. RELATION writes. `->attendees()->create(` is the obvious one; naming only `create` draws
     *     an arbitrary line through one API, so its seven siblings are needled too. `->clinics()`
     *     is covered although no such relation exists yet — a `Unit::clinics()` hasMany is the
     *     natural next addition and would be a second writer the moment somebody called `create()`
     *     on it.
     *  5. `$model->update([...])` — THE HOUSE IDIOM, and the one this guard shipped blind to
     *     (P1e-1 adversarial review finding 2). It is not a hypothetical shape here: it is how
     *     `UnitController`, `LevelController` and `HolidayController` each write their own
     *     `setActive()`, so it is precisely what a fourth controller written beside them would
     *     reach for. Measured before the fix by planting a file that rewrote `weekday`, `session`,
     *     `attendee_mode`, `clinic_id`, `name` and `active` across both tables through nothing but
     *     `update([...])` — this test stayed GREEN. An entire endpoint could have bypassed
     *     `ClinicWriter` with the build passing.
     *
     * Plus `find()`-then-`delete()`, `destroy(` and `truncate(`. There is deliberately no destroy
     * path for a clinic anywhere (invariant 7 — a clinic that stopped running is DEACTIVATED,
     * UN-04's "hides forward, never deletes history"), so the destructive shapes are needles rather
     * than allow-list candidates.
     *
     * COLUMN NEEDLES ARE PROPERTY-ONLY OR `update(`-QUALIFIED, AND THAT IS A DELIBERATE NARROWING.
     * The BARE array-key twins (`'weekday' =>`, `'attendee_mode' =>`, `'clinic_id' =>`) were
     * written first and removed after checking what Task 4 must contain: an Inertia `present()`
     * map emitting `'weekday' => $clinic->weekday` is a READ, and needling it would force
     * `ClinicController` onto the allow-list — blinding this guard at precisely the file where a
     * second writer is most likely to appear. A needle that makes you exempt the risky file is
     * worse than no needle. `->update(['weekday'` is the narrowing that recovers the coverage
     * without the cost: it cannot match a projection map, because the key is inside a call whose
     * only meaning is a write. It is `AccountLinkHasOneWriterTest`'s `->update(['person_id'` and
     * `PersonActiveHasOneWriterTest`'s `$person->update(['active'`, applied to these two tables.
     *
     * MEASURED, per ruling 42, over app/ + database/ + routes/ before anything was added:
     *   - `->update(['weekday'`, `['session'`, `['attendee_mode'`, `['clinic_id'` — ZERO matches,
     *     in either quote style. No allow-list entry, no incidental match.
     *   - `$clinic->update(`, `$attendee->update(` — ZERO matches. These reach the columns the
     *     column-qualified set deliberately cannot (`name`, `active`, `location`, `note`,
     *     `unit_id`), which shrinks the honest gap stated below rather than closing it.
     *   - `->update(['active'` was written, MEASURED AND WITHDRAWN. It matches six files
     *     (`UnitController`, `LevelController`, `HolidayController`, `UserManagementController`,
     *     `PersonStatus`, `UnitMerge`), every one of them writing a DIFFERENT table's `active`
     *     column. That is five or six allow-list entries bought for one column — and
     *     `UnitController` and `UnitMerge` are the two files most likely to grow a real clinic
     *     writer, `UnitMerge` especially: it re-points four unit-owned tables today and does not
     *     re-point `clinics.unit_id` (design §14), so an allow-list entry there would blind this
     *     guard on the exact file the next real offender arrives in. Withdrawn on the measurement,
     *     not on taste.
     *   - `$row->update(` was rejected for the opposite reason: `$row` is a general-purpose local
     *     throughout this codebase, so the needle is fragile toward FALSE positives rather than
     *     cheap. `$clinic`/`$attendee` name these two models and nothing else in the tree.
     *
     * WHAT THE COLUMN-QUALIFIED HALF CANNOT SEE, observed on the plant rather than reasoned about:
     * it matches only the FIRST key of the array. `$clinic->update(['weekday' => 3, 'session' =>
     * 'PM'])` fired `->update(['weekday'` and did NOT fire `->update(['session'`. That is inherent
     * to a substring scan over a multi-key literal and is why the variable-qualified half is not
     * redundant with it — on that same plant, `$clinic->update(` named the file for the write the
     * column needles were blind to. The two halves fail for different reasons, which is the
     * property that makes keeping both worth the four extra strings.
     *
     * ALSO DELIBERATELY ABSENT, and honestly so: `->name = `, `->location = `, `->note = `,
     * `->active = `. Every one is a column of `units`, `levels`, `people` or `holidays` as well, so
     * each would buy allow-list entries for unrelated files. The residual gap is therefore
     * `$c->name = 'x'; $c->save();` where the variable is named anything but `$clinic` — stated
     * rather than implied, the same way `InvitationWritersAreSingularTest` states its
     * `$invitation->delete()` blind spot. `weekday`, `session`, `attendee_mode` and `clinic_id`
     * belong to these two tables and to nothing else in the schema (verified by grep over app/ +
     * database/ + routes/ before this list was written: zero pre-existing matches for any of them,
     * in either spelling).
     *
     * ---------------------------------------------------------------------------------------
     * THE 2026-08-12 SWEEP (ruling 66). Twenty-two probes were planted against this guard — the
     * seventeen writer shapes, with the column-sensitive ones run against both a signature column
     * and a shared one. It scored among the strongest of the eleven, EIGHTEEN named for `clinics`
     * and SEVENTEEN for `clinic_attendees` (behind only `DemoRowsAreLedgeredTest`'s twenty and
     * `CapabilityWritersAreSingularTest`'s nineteen), and it still had the sixth shape open:
     * `Clinic::query()->create([...])` was planted as a real file under `app/` and this test stayed
     * GREEN. Closed above, verb-qualified for `Clinic` and whole for `ClinicAttendee`, on the
     * measurements recorded at each.
     *
     * RESIDUALS. No substring reaches these and none is closed:
     *   - `$clinic->delete()` / `$attendee->delete()` on an already-bound instance. Invisible to
     *     any substring scan, here as in every sibling guard — and the reason `Clinic::find(`,
     *     `Clinic::destroy(` and the relation shapes are needled: they are every route TO such an
     *     instance that does not go through route-model binding.
     *   - `Clinic::query()->where(...)->delete()` — a `where` between the model and the verb, which
     *     no one-token needle spans. `ClinicAttendee` does not share this gap (its `::query(` is
     *     needled whole); `Clinic` does, at the price of not blinding `ClinicController`.
     *   - `$clinic->forceFill(['weekday' => 3])->save()`. Measured ZERO and deliberately NOT
     *     bought: `forceFill(['weekday'` reaches only a single-line call whose FIRST key is that
     *     column, which is a fraction of what `$clinic->update(` already sees, and this codebase
     *     formats payload arrays across lines.
     *   - The column-qualified `->update(['col'` family matches only a SINGLE-LINE call whose
     *     FIRST array key is that column — the first half was already recorded here from a plant;
     *     the same-line half was added by the sweep, and it is why the symmetric
     *     `->create(['weekday'` family measures zero for a reason that has nothing to do with
     *     safety.
     */
    private const NEEDLES = [
        'Clinic::create(',
        'Clinic::insert(',
        'Clinic::updateOrCreate(',
        'Clinic::firstOrCreate(',
        'Clinic::upsert(',
        'Clinic::destroy(',
        'Clinic::truncate(',
        'Clinic::find(',
        // THE SIXTH WRITER SHAPE (2026-08-12 sweep, ruling 66). `Clinic::query()->create([...])`
        // reaches the table through the builder and matched none of the eight statics above —
        // proved by planting exactly that file and watching this test stay green, the same
        // mutation that found it in `DemoRowsAreLedgeredTest`. Verb-qualified rather than the
        // bare `Clinic::query(`, which measures FIVE files (`ClinicController`,
        // `ClinicMapController`, `DepartmentSetup`, `E2eSeeder`, plus the writer) — an entry for
        // `ClinicController` is precisely the blinding this guard's own docblock warns about, at
        // the file where a second writer is most likely to appear.
        'Clinic::query()->create(',
        'Clinic::query()->insert(',
        'Clinic::query()->firstOrCreate(',
        'Clinic::query()->updateOrCreate(',
        'Clinic::query()->upsert(',
        'ClinicAttendee::create(',
        'ClinicAttendee::insert(',
        'ClinicAttendee::updateOrCreate(',
        'ClinicAttendee::firstOrCreate(',
        'ClinicAttendee::upsert(',
        'ClinicAttendee::destroy(',
        'ClinicAttendee::truncate(',
        'ClinicAttendee::find(',
        // `ClinicAttendee::query(` measures ZERO across the whole tree — this model is reached
        // only through `Clinic::attendees()` — so unlike `Clinic::query(` it can be taken WHOLE,
        // which is strictly wider than the verb-qualified form: it also covers
        // `::query()->where(...)->delete()` and a chain broken across lines, neither of which any
        // one-token needle can span. Same trade `DemoRowsAreLedgeredTest` took for
        // `DemoRow::query()`, available here for the same reason (nothing reads it directly) and
        // NOT available for `Clinic`.
        'ClinicAttendee::query(',
        "DB::table('clinics')",
        'DB::table("clinics")',
        "DB::table('clinic_attendees')",
        'DB::table("clinic_attendees")',
        '->attendees()->create(',
        '->attendees()->insert(',
        '->attendees()->updateOrCreate(',
        '->attendees()->firstOrCreate(',
        '->attendees()->save(',
        '->attendees()->saveMany(',
        '->attendees()->update(',
        '->attendees()->delete(',
        '->clinics()->create(',
        '->clinics()->insert(',
        '->clinics()->updateOrCreate(',
        '->clinics()->firstOrCreate(',
        '->clinics()->save(',
        '->clinics()->saveMany(',
        '->clinics()->update(',
        '->clinics()->delete(',
        '->attendee_mode = ',
        '->clinic_id = ',
        '->weekday = ',
        '->session = ',
        // Shape 5, column-qualified: catches the idiom whatever the variable is called.
        "->update(['attendee_mode'",
        '->update(["attendee_mode"',
        "->update(['clinic_id'",
        '->update(["clinic_id"',
        "->update(['weekday'",
        '->update(["weekday"',
        "->update(['session'",
        '->update(["session"',
        // Shape 5, variable-qualified: catches it whatever the COLUMN is, which is the only reach
        // this guard has over `name`, `active`, `location`, `note` and `unit_id` — every one of
        // which is another table's column too and so cannot be needled by name.
        '$clinic->update(',
        '$attendee->update(',
        // Property-assign-then-save() ON `unit_id`, added 2026-08-12 with design §14 item 23's fix.
        // Its sibling in `RotaWritersAreSingularTest` states the reasoning; the same hole was open
        // here, on the same column, for the same reason — and this guard's own docblock named
        // `UnitMerge` as the file a real `clinics` writer would most likely appear in. It re-points
        // `clinics.unit_id` now, through `ClinicWriter::repointUnit()`, so the shape a future
        // shortcut would reach for is exactly this one. TRAILING SPACE load-bearing: without it,
        // this matches `(int) $clinic->unit_id`, which `ClinicRoster` and `ClinicController` both
        // read legitimately (measured: 2 such reads, zero writes).
        // MEASURED before adding: ZERO matches anywhere, in app/, database/ or routes/ — the
        // writer sets the column through `fill()`. No allow-list entry bought. Proved by planting.
        '$clinic->unit_id = ',
    ];

    public function test_only_the_clinic_writer_writes_the_clinic_tables(): void
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
            '`clinics` and `clinic_attendees` must be written only by '
            .'App\\Support\\Clinics\\ClinicWriter (P1e Decision B). Neither SQLite nor MySQL 8.4 can '
            .'express the exactly-one-of, no-duplicate and homogeneous-with-the-mode constraints, so '
            ."a second writer does not weaken the guarantee — it removes it.\n"
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
     * THE VACUITY TWIN (2026-08-12 sweep, ruling 66). The scan above is satisfied by a tree in
     * which nothing writes these tables at all — the offender loop iterates files that mention
     * nothing and `assertSame([], $offenders)` passes. `DemoRowsAreLedgeredTest` has carried this
     * twin since P1e and nine siblings did not, so the writer must actually match a needle.
     */
    public function test_the_one_writer_really_does_write_the_clinic_tables(): void
    {
        $source = (string) File::get(base_path('app/Support/Clinics/ClinicWriter.php'));

        $matched = array_values(array_filter(
            self::NEEDLES,
            static fn (string $needle): bool => str_contains($source, $needle),
        ));

        $this->assertNotSame([], $matched,
            'ClinicWriter matches none of this guard\'s needles, so the guard is scanning for a '
            .'shape nothing in the tree uses — it would stay green against a second writer '
            .'spelled the way the real one is.');
    }
}
