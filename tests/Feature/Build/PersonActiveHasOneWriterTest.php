<?php

namespace Tests\Feature\Build;

use Illuminate\Support\Facades\File;
use Tests\TestCase;

/**
 * Review finding 4: `App\Support\PersonStatus` is the ONE writer of `people.active` that also
 * keeps a linked account's `users.active` in step. Before it existed there were three writers and
 * only `PersonController::applySetActive()` (LV-02's bulk tool) kept the two flags together —
 * `PersonController::update()` and `Promotion::commit()`'s retire path each wrote `people.active`
 * directly, so a person deactivated through either screen stopped being NAMED but kept the
 * ability to log in and read handover sheets carrying patient PHI.
 *
 * Same species as `PersonLevelsHaveOneWriterTest` and `ContactFieldsAreProjectedOnceTest`: a
 * plain substring scan over app/ + database/ + routes/, deliberately coarse rather than a static
 * analyser — the point is a stop-sign a future PR trips over, not perfect precision. The needles
 * are scoped to the variable-name convention this codebase already uses consistently for a
 * `Person` instance (`$person`) and for a User's linked person (`->person`), not a bare
 * `'active' =>` (which would also match Unit/Level/Holiday/User's OWN unrelated `active` column
 * and make this guard useless noise).
 *
 * `tests/` IS NOT SCANNED; `database/` IS, factories and seeders included — the verdict every
 * sibling now states in the same words (rulings 44/49 house style, made uniform by the 2026-08-12
 * sweep, ruling 66). A factory or seeder writing the guarded column IS a second writer of the shape
 * this guard is about; it is merely one whose blast radius stops at the suite. Naming it costs one
 * allow-list line and makes the exemption reviewable, where excluding the directory makes every
 * future factory exempt in advance, invisibly. `tests/` stays out because a guard that named its
 * own fixtures would be unusable.
 *
 * THIS GUARD WAS THE WEAKEST OF THE ELEVEN WHEN THE SWEEP PROBED THEM (ruling 66), by a distance:
 * EIGHTEEN of twenty-two probes walked through, against two for the best-covered guard. The four
 * it named came from only TWO needle families — `$person->update(['active'` and the raw builder.
 * Everything else walked through — and one of them was not hypothetical.
 * `InvitationAcceptController` has been writing `$person->active = true; $person->save();` since
 * redemption was built, a second writer of the guarded column sitting in the tree, unnamed and
 * invisible, for as long as this guard has existed. It is benign (see its allow-list entry) and it
 * is exactly what a guard blind to the house idiom cannot tell you.
 *
 * RESIDUALS, stated rather than implied — no substring reaches these and none is closed:
 *   - `Person::query()->whereIn(...)->update(['active' => false])`. A bulk flag flip through the
 *     builder, with a `where` between the two halves, so no single needle spans it.
 *     `Person::query(` names TWELVE files (measured) and `Person::where(` names zero but is
 *     read-shaped — it would fire on the first legitimate direct read anybody writes. Both
 *     withdrawn; what holds this line is behavioural (`PersonBulkTest`, and
 *     `PersonController::bulk()` routing every row through `PersonStatus::apply()`).
 *   - `$p->active = false;` where the variable is named anything but `$person`.
 *   - `Person::create(['active' => false])`. Creating a roster row is not a status CHANGE, and the
 *     needle would name seven files including `PersonController` and `RosterImport` — the two most
 *     likely homes of a real offender.
 */
class PersonActiveHasOneWriterTest extends TestCase
{
    /** Every file allowed to write `people.active`, with why. */
    private const ALLOW_LIST = [
        // The one writer. This is the control.
        'app/Support/PersonStatus.php',
        // The ACCOUNT-side mirror, pre-dating this consolidation and already correct: it keeps
        // `users.active` and `people.active` in step from the OTHER direction
        // (`UserManagementController::setActive()`, with its own last-administrator guard).
        // Finding 4 named three broken sites (`PersonController::update()`,
        // `Promotion::commit()`'s retire path, and the reference-correct
        // `PersonController::applySetActive()`) — this file was not one of them, and folding it
        // into `PersonStatus::apply()` too is a separate, larger refactor than this fix asked
        // for (it would need `PersonStatus::apply()` to accept a User with a possibly-null
        // linked Person, a different shape from every other call site). Left as-is and allow-
        // listed rather than silently refactored.
        'app/Http/Controllers/Admin/UserManagementController.php',
        // The ONE exception CLAUDE.md already documents for a different column
        // (`users.member_email`) and applies equally here: LegacyImport is one-way, read-only
        // against its source, idempotent and owner-run only against production — never a live
        // application write path. It raw-upserts BOTH `people.active` and `users.active` from
        // the legacy row's own `active` column, deliberately, because there is no `people` row
        // yet for `PersonStatus::apply()` to operate on during a historical bulk import.
        'app/Console/Commands/LegacyImport.php',
        // D3's reversal migration: a ONE-TIME backfill giving `people` its first rows, one per
        // pre-existing `users` row, copying `(bool) $u->active` verbatim onto the new person —
        // there is no PersonStatus to call yet at this point in the schema's own history, and no
        // status is being CHANGED, only carried across the split.
        'database/migrations/2026_08_10_120001_create_people_and_link_users.php',
        // Backfills `institution_id` only (`whereNull('institution_id')->update(['institution_id'
        // => $id])`) — matched only because the `DB::table('people')` needle cannot distinguish
        // which column a raw query writes.
        'database/migrations/2026_08_11_120001_backfill_institution_on_identity_rows.php',
        // FOUND BY THE 2026-08-12 SWEEP, not by review (ruling 66): redemption writes
        // `$person->active = true; $person->save();` inside its own locked transaction, and no
        // needle here could see it. It is benign, and the reason is worth stating rather than
        // trusting: `PersonStatus::apply()`'s two jobs are (a) keep `users.active` in step and
        // (b) refuse to strip the last `access.manage` holder. Neither applies here. The account
        // does not exist yet — it is inserted four statements later with `'active' => true`, so
        // the two flags are in step BY CONSTRUCTION rather than by being written together — and
        // the ACTIVATING direction can only add a holder, which is the same `null` argument
        // `PersonStatus` itself passes to `AccessManageGuard::guarding()`. Routing it through
        // `PersonStatus::apply()` would additionally re-query for an account that cannot be there
        // (`hasAccount()` throws below if it is). Named here rather than silently refactored.
        'app/Http/Controllers/Auth/InvitationAcceptController.php',
    ];

    /**
     * MEASURED, per ruling 42, over app/ + database/ + routes/ before anything was added:
     *   - `$person->active = ` — ONE match, `InvitationAcceptController`, which is a REAL second
     *     writer of this column and is allow-listed above with its reason. That is the good case
     *     ruling 42 distinguishes: the entry is earned by a genuine write of the guarded column,
     *     not by an incidental match on somebody else's.
     *   - `->person->active = `, `->person?->active = ` — ZERO matches, in either quote style. No
     *     allow-list entry bought.
     *
     * WITHDRAWN ON MEASUREMENT, not on taste:
     *   - `->update(['active'` — SIX files (`HolidayController`, `LevelController`,
     *     `UnitController`, `UserManagementController`, `PersonStatus`, `UnitMerge`), every one of
     *     them writing a DIFFERENT table's `active` column. `ClinicWritersAreSingularTest` withdrew
     *     this exact needle for this exact reason and the measurement is unchanged; `UnitMerge` has
     *     since been proved right about — it was the file the next real offender arrived in.
     *   - `->active = ` (bare) — two files, one of them `ReferenceSeeder` writing
     *     `$institution->active`. `$person->active = ` is the narrowing that keeps the reach and
     *     buys nothing extra, which is what ruling 42 asks for.
     *   - `Person::query(` (12 files) and `Person::where(` (0 today, but read-shaped) — see the
     *     residuals in the class docblock.
     *   - `forceFill(['active'` measures ZERO today and was still WITHDRAWN, because the
     *     measurement that killed `->update(['active'` kills it for the same reason one move
     *     later: `active` is a column of `units`, `levels`, `holidays` and `users` as well, so the
     *     first `$unit->forceFill(['active' => …])` anybody writes buys `UnitController` an entry
     *     here. Its reach — a single-line `forceFill` whose FIRST key is `active` — does not pay
     *     for that risk. A narrower `$person->forceFill(['active'` would be free and reaches
     *     almost nothing beyond what `$person->active = ` already sees. Listed as a residual
     *     instead of bought.
     */
    private const NEEDLES = [
        "\$person->update(['active'",
        '$person->update(["active"',
        "->person?->update(['active'",
        '->person?->update(["active"',
        "->person->update(['active'",
        '->person->update(["active"',
        "DB::table('people')",
        'DB::table("people")',
        // THE HOUSE PROPERTY-ASSIGN SHAPE, added by the 2026-08-12 sweep. `$person->active = false;`
        // followed by `->save()` matched NOTHING here, and it is the shape finding 4's three
        // original offenders are one refactor away from — dropping `update([...])` for a property
        // write is a tidy-up nobody would review twice. The TRAILING SPACE is load-bearing, as it
        // is for `->person_id = ` next door: without it this also matches `->active === true`, a
        // read several screens perform legitimately.
        '$person->active = ',
        '->person->active = ',
        // NOT `->person?->active = `, although its `update([...])` twin above is real: `$u->person
        // ?->active = true;` is a PHP PARSE ERROR ("Can't use nullsafe operator in write context"),
        // so the needle could never match code — only prose. Found by trying to plant it (ruling
        // 64). A needle for a shape the language forbids is the dead `->users()->create(` next door
        // in `RosterNeverMintsCredentialsTest`, and is left out rather than left in.
    ];

    public function test_only_person_status_writes_a_persons_active_flag(): void
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
            "people.active must be written only by App\\Support\\PersonStatus, which keeps the linked ".
            "account's users.active in step (review finding 4).\n".implode("\n", $offenders));
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
     * which nothing writes `people.active` at all — the offender loop iterates files that mention
     * nothing and `assertSame([], $offenders)` passes. `DemoRowsAreLedgeredTest` has carried this
     * twin since P1e and nine siblings did not, so the writer must actually match a needle. That
     * matters more here than anywhere: this guard shipped with EIGHT needles, two of which the
     * writer matches, and the sweep found it blind to eighteen of the twenty-two probes.
     */
    public function test_the_one_writer_really_does_write_the_active_flag(): void
    {
        $source = (string) File::get(base_path('app/Support/PersonStatus.php'));

        $matched = array_values(array_filter(
            self::NEEDLES,
            static fn (string $needle): bool => str_contains($source, $needle),
        ));

        $this->assertNotSame([], $matched,
            'PersonStatus matches none of this guard\'s needles, so the guard is scanning for a '
            .'shape nothing in the tree uses — it would stay green against a second writer '
            .'spelled the way the real one is.');
    }
}
