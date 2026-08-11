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
     * THE FOUR WRITER SHAPES. Each was proved by planting a writer of exactly that shape in a
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
     *
     * Plus `find()`-then-`delete()`, `destroy(` and `truncate(`. There is deliberately no destroy
     * path for a clinic anywhere (invariant 7 — a clinic that stopped running is DEACTIVATED,
     * UN-04's "hides forward, never deletes history"), so the destructive shapes are needles rather
     * than allow-list candidates.
     *
     * COLUMN NEEDLES ARE PROPERTY-ONLY, AND THAT IS A DELIBERATE NARROWING. The array-key twins
     * (`'weekday' =>`, `'attendee_mode' =>`, `'clinic_id' =>`) were written first and removed after
     * checking what Task 4 must contain: an Inertia `present()` map emitting
     * `'weekday' => $clinic->weekday` is a READ, and needling it would force `ClinicController`
     * onto the allow-list — blinding this guard at precisely the file where a second writer is
     * most likely to appear. A needle that makes you exempt the risky file is worse than no needle.
     *
     * ALSO DELIBERATELY ABSENT, and honestly so: `->name = `, `->location = `, `->note = `,
     * `->active = `. Every one is a column of `units`, `levels`, `people` or `holidays` as well, so
     * each would buy allow-list entries for unrelated files. The consequence is a real gap —
     * `$clinic->name = 'x'; $clinic->save();` is invisible to this scan — stated rather than
     * implied, the same way `InvitationWritersAreSingularTest` states its `$invitation->delete()`
     * blind spot. `weekday`, `session`, `attendee_mode` and `clinic_id` belong to these two tables
     * and to nothing else in the schema (verified by grep over app/ + database/ + routes/ before
     * this list was written: zero pre-existing matches for any of them, in either spelling).
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
        'ClinicAttendee::create(',
        'ClinicAttendee::insert(',
        'ClinicAttendee::updateOrCreate(',
        'ClinicAttendee::firstOrCreate(',
        'ClinicAttendee::upsert(',
        'ClinicAttendee::destroy(',
        'ClinicAttendee::truncate(',
        'ClinicAttendee::find(',
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
}
