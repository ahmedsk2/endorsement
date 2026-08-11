<?php

namespace Tests\Feature\Build;

use Illuminate\Support\Facades\File;
use Tests\TestCase;

/**
 * P1c Decision G: `App\Support\LevelAssignment` is the ONLY writer of `person_levels`. Finding 4:
 * before it existed, the table had no overlap constraint, no batch identity, no reason and no
 * author, and two open-ended spans for one person coexisted happily — `Person::levelAt()` (and
 * its set-wise sibling) silently resolved whichever sorted last, no error, no warning. A second
 * writer anywhere defeats the audit trail LV-04 exists to provide.
 *
 * Same species as `ContactFieldsAreProjectedOnceTest` and `CalendarWritersFlushTest`: a plain
 * substring scan over app/ + database/ + routes/, deliberately coarse rather than a static
 * analyser, because the point is a stop-sign a future PR trips over, not perfect precision.
 *
 * `tests/` is NOT scanned: `LevelHistoryTest`'s own fixtures call `PersonLevel::create()`
 * directly to seed history for `levelAt()`'s tests (it predates this guard, and asserting
 * `levelAt()`'s read semantics is exactly what that file is for) — a test fixture is not the
 * production integrity surface this guard exists to close.
 *
 * ONE PATH DELETES THIS TABLE WITHOUT THIS GUARD BEING ABLE TO SEE IT, AND IT IS NOT AN OVERSIGHT.
 * `App\Support\Demo\DemoDepartment::remove()` (P1e Task 13) hard-deletes every row the demo
 * department created, which includes the level spans it opened through `LevelAssignment::assign()`.
 * It deletes by walking `demo_rows` and taking the TABLE NAME FROM THE LEDGER ROW, so the query
 * reads `DB::table($table)` and no substring needle can match it. It is deliberately NOT on
 * ALLOW_LIST — an entry would exempt the file from every needle here while buying no green. What
 * holds the line is behavioural: `DemoRoundTripTest` compares every table in the live schema around
 * a create-and-remove.
 */
class PersonLevelsHaveOneWriterTest extends TestCase
{
    /** Every file allowed to write `person_levels`, with why. */
    private const ALLOW_LIST = [
        // The one writer. This is the control.
        'app/Support/LevelAssignment.php',
        // A factory populating fixture history for OTHER tests is not a production writer —
        // same reasoning ContactFieldsAreProjectedOnceTest applies to database/factories/.
        'database/factories/PersonLevelFactory.php',
    ];

    /**
     * THE `$model->update([...])` SHAPE, added after `ClinicWritersAreSingularTest` was found blind
     * to it (P1e-1 adversarial review finding 2) and this guard was probed for the same hole. It
     * had it, and worse than its siblings: `LevelAssignment` ALREADY WRITES THIS WAY, twice —
     * `assign()` closes the open prior span with `->update(['effective_to' => ...])` and `close()`
     * does the same on a bound instance. The one shape this guard could not see was the live idiom
     * of the very table it guards. MEASURED on a throwaway file under `app/` that rewrote
     * `effective_from`, `effective_to` and `promotion_batch_id` through nothing but `update([...])`
     * plus `->levels()->update(` and a double-quoted raw builder: this test stayed GREEN. A second
     * writer could have re-dated every span in the department — the exact Finding-4 state, two
     * spans coexisting and `levelAt()` silently resolving whichever sorted last — with the build
     * passing.
     *
     * MEASURED, per ruling 42, over app/ + database/ + routes/ BEFORE anything was added:
     *   - `->update(['effective_from'`, `['promotion_batch_id'`, `->levels()->update(`,
     *     `$personLevel->update(`, `DB::table("person_levels")` — ZERO matches.
     *   - `->update(['effective_to'` — ONE match, `app/Support/LevelAssignment.php`, which is the
     *     allow-listed CONTROL. See the note below; it buys no new entry.
     *
     * WITHDRAWN ON MEASUREMENT, not on taste:
     *   - `$level->update(` matches `app/Http/Controllers/Admin/LevelController.php`, which writes
     *     `levels` — a different table. The entry it would buy is the admin controller closest to
     *     the ladder, the natural home for a promotion screen, i.e. the file most likely to grow a
     *     real `person_levels` writer. Exactly the trap `ClinicWritersAreSingularTest` withdrew
     *     `->update(['active'` over.
     *   - `->update(['level_id'` is ZERO today but `level_id` is also a `clinic_attendees` column,
     *     so it would eventually buy an entry for `ClinicWriter`. Its reach here is near nil
     *     anyway: this table is SPAN-BASED, so changing someone's level opens a new row — nothing
     *     legitimate or illegitimate updates `level_id` in place.
     *   - `->update(['reason'` / `['created_by'` — ZERO today, but both are generic provenance
     *     names a future table will claim, and `close()` deliberately never writes either (they
     *     record who OPENED a span). An offender writing only those columns is not a real shape.
     *   - `$span->update(` — `$span` names a `PersonLevel` in `Person.php`/`PersonPresenter` AND a
     *     rota span under `app/Support/Rota/`; whichever guard claims it fires on the other's
     *     writes. Withdrawn from both.
     *   - `$open->update(` matches only `LevelAssignment` and so is free, but it is redundant with
     *     `->update(['effective_to'` (that is the only column `close()` writes) and has no reach
     *     outside the writer. A needle bought for nothing is clutter, not defence.
     *
     * ON THE ONE PRE-EXISTING MATCH, and why it is not a purchase. `LevelAssignment.php` was
     * ALREADY wholly exempt before this change — the allow-list is FILE-scoped and `continue`s
     * before any needle runs, so every needle in this list is already blind inside it. Adding one
     * more that it matches introduces no new blindness and adds no entry. The honest limit, stated
     * rather than implied: this guard cannot see a SECOND writer added inside the writer's own
     * file, and never could — that is true of `ClinicWriter`, `AccountUnbind` and every other
     * control on every sibling guard. What holds the line inside the file is behavioural, not
     * source-level: `tests/Feature/Identity/LevelAssignmentTest.php` pins all four outcomes of
     * `assign()` plus both of `close()`, `LevelHistoryTest` pins `levelAt()`'s resolution over the
     * spans those produce, and `LevelResolverParityTest` pins the single-person and set-wise
     * resolvers against each other — a second write path added beside `assign()` that reopened the
     * overlap breaks those, at the assertion rather than at the grep. The file-level entry was
     * checked for this specifically and not merely inherited: it costs nothing NEW, because it was
     * already total.
     */
    private const NEEDLES = [
        'PersonLevel::create(',
        'PersonLevel::insert(',
        '->levels()->create(',
        "DB::table('person_levels')",
        // The double-quoted twin of the raw-builder needle above, absent until now for no reason
        // but oversight — every sibling guard carries both spellings. ZERO pre-existing matches.
        'DB::table("person_levels")',
        // Column-qualified: catches the idiom whatever the variable is called. These three columns
        // are carried by `person_levels` and by no other table in the schema.
        "->update(['effective_from'",
        '->update(["effective_from"',
        "->update(['effective_to'",
        '->update(["effective_to"',
        "->update(['promotion_batch_id'",
        '->update(["promotion_batch_id"',
        // The update twin of `->levels()->create(` above — completing a pair that was half needled.
        // `Person::levels()` is a real relation, so this is reachable today, not pre-emptive.
        '->levels()->update(',
        // Variable-qualified: catches it whatever the COLUMN is, which is the only reach this guard
        // has over `level_id`, `reason` and `created_by` — each withdrawn by name above.
        '$personLevel->update(',
    ];

    public function test_only_level_assignment_writes_person_levels(): void
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
            "person_levels must be written only by App\\Support\\LevelAssignment (P1c Decision G).\n"
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
