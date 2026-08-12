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
 * `tests/` IS NOT SCANNED: `LevelHistoryTest`'s own fixtures call `PersonLevel::create()`
 * directly to seed history for `levelAt()`'s tests (it predates this guard, and asserting
 * `levelAt()`'s read semantics is exactly what that file is for) — a test fixture is not the
 * production integrity surface this guard exists to close. `database/` IS SCANNED, factories and
 * seeders included — the verdict every sibling now states in the same words (made uniform by the
 * 2026-08-12 sweep, ruling 66; the code has always scanned it, since `base_path('database')`
 * contains `factories/`). A factory writing the guarded table IS a second writer of the shape this
 * guard is about, merely one whose blast radius stops at the suite, which is why
 * `PersonLevelFactory` is NAMED on the allow-list rather than exempted by directory — a reviewable
 * exemption where excluding the directory makes every future factory exempt in advance, invisibly.
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
     *
     * ---------------------------------------------------------------------------------------
     * THE 2026-08-12 SWEEP (ruling 66). Twenty-two probes were planted against this guard — the
     * seventeen writer shapes, with the column-sensitive ones run against both a signature column
     * and a shared one — and THIRTEEN walked through, level with `vacations` and behind only
     * `PersonActiveHasOneWriterTest`'s eighteen.
     * `PersonLevel::query()->create(['effective_from' => …])`,
     * `firstOrCreate`, `updateOrCreate`, `upsert`, `destroy`, `truncate`, every relation write
     * except `create`, and the property-assign that is this codebase's house idiom were all
     * invisible. On a SPAN table, `PersonLevel::query()->create(...)` is the exact Finding-4
     * state: two open spans for one person, `levelAt()` silently resolving whichever sorted last.
     *
     * MEASURED, per ruling 42, over app/ + database/ + routes/ before anything below was added:
     * every new needle matched ZERO files, in either quote style. No allow-list entry bought.
     *
     * WITHDRAWN ON MEASUREMENT: `PersonLevel::query(` taken whole — FOUR files (`Person.php`,
     * `DemoDepartment`, `Promotion`, plus the writer). `Promotion` is the natural home of the
     * annual-promotion writer and `Person.php` holds `levelAt()`; an entry for either is the
     * blinding ruling 42 forbids. Verb-qualified instead, at the residual cost stated below.
     *
     * RESIDUALS. No substring reaches these and none is closed:
     *   - `$span->delete()` on an already-bound instance.
     *   - `PersonLevel::query()->where(...)->delete()` — a `where` between the model and the verb,
     *     which no one-token needle spans.
     *   - A MULTI-LINE builder chain (`PersonLevel::query()` then `->create(` on the next line).
     *   - The column-qualified `->update(['col'` family matches only a SINGLE-LINE call whose
     *     FIRST array key is that column — which is why the symmetric `->create(['effective_from'`
     *     family measures zero for a reason that has nothing to do with safety, and was not
     *     bought: this codebase formats create payloads across lines.
     */
    private const NEEDLES = [
        'PersonLevel::create(',
        'PersonLevel::insert(',
        // Added by the 2026-08-12 sweep: the static verbs this list never carried, plus the sixth
        // writer shape verb-qualified. `firstOrCreate`/`updateOrCreate`/`upsert` open a span
        // without ever calling `create`, and `destroy`/`truncate` remove history LV-04 exists to
        // keep. Each was proved by planting a file of exactly that shape under `app/`.
        'PersonLevel::firstOrCreate(',
        'PersonLevel::updateOrCreate(',
        'PersonLevel::upsert(',
        'PersonLevel::destroy(',
        'PersonLevel::truncate(',
        'PersonLevel::find(',
        'PersonLevel::query()->create(',
        'PersonLevel::query()->insert(',
        'PersonLevel::query()->firstOrCreate(',
        'PersonLevel::query()->updateOrCreate(',
        'PersonLevel::query()->upsert(',
        '->levels()->create(',
        // The rest of the relation surface. `Person::levels()` is a real hasMany, so every one of
        // these is reachable today — naming only `create` and `update` drew an arbitrary line
        // through one API, the same objection `InvitationWritersAreSingularTest` records.
        '->levels()->insert(',
        '->levels()->firstOrCreate(',
        '->levels()->updateOrCreate(',
        '->levels()->upsert(',
        '->levels()->save(',
        '->levels()->saveMany(',
        '->levels()->delete(',
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
        // PROPERTY-ASSIGN then `->save()`, added by the 2026-08-12 sweep and previously absent
        // altogether. `$span->effective_to = $date;` matched NO needle here — and it is one
        // refactor away from live code, since `close()` writes exactly that column through
        // `update([...])` today. Column-qualified so it fires whatever the local is called
        // (`$span` itself is withdrawn as a variable name above, for colliding with a rota span).
        // TRAILING SPACE load-bearing: without it, `->effective_to` also matches
        // `->effective_to === null`, the open-span READ `levelAt()` performs. These three columns
        // are carried by `person_levels` and by no other table. MEASURED: ZERO matches each.
        '->effective_from = ',
        '->effective_to = ',
        '->promotion_batch_id = ',
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

    /**
     * THE VACUITY TWIN (2026-08-12 sweep, ruling 66). The scan above is satisfied by a tree in
     * which nothing writes `person_levels` at all. `DemoRowsAreLedgeredTest` has carried this twin
     * since P1e and nine siblings did not, so the writer must actually match a needle. It does so
     * through `->update(['effective_to'` — the ONE pre-existing match this guard's own docblock
     * explains at length, which is what makes the twin non-trivial here rather than decoration.
     */
    public function test_the_one_writer_really_does_write_person_levels(): void
    {
        $source = (string) File::get(base_path('app/Support/LevelAssignment.php'));

        $matched = array_values(array_filter(
            self::NEEDLES,
            static fn (string $needle): bool => str_contains($source, $needle),
        ));

        $this->assertNotSame([], $matched,
            'LevelAssignment matches none of this guard\'s needles, so the guard is scanning for a '
            .'shape nothing in the tree uses — it would stay green against a second writer '
            .'spelled the way the real one is.');
    }
}
