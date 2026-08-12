<?php

namespace Tests\Feature\Build;

use Illuminate\Support\Facades\File;
use Tests\TestCase;

/**
 * P1c Decision I: nothing in P1c creates an account. The invitation flow remains the ONLY path
 * from a roster entry to a credential — a roster row creates or updates a `people` row and
 * nothing else. Same species of guard as `PersonLevelsHaveOneWriterTest` and
 * `ContactFieldsAreProjectedOnceTest`: a plain substring scan, deliberately coarse rather than a
 * static analyser, over the FOUR files Decision I names — the point is a stop-sign a future PR
 * trips over, not perfect precision.
 */
class RosterNeverMintsCredentialsTest extends TestCase
{
    /**
     * Decision I names the first four files explicitly — no allow-list, because none should exist.
     *
     * P1d-2 Task 11 adds the rota importer. An import path must not mint an account, and the rota
     * importer is the second file in this codebase that reads an operator-supplied spreadsheet and
     * writes rows off the back of it — exactly the shape Decision I exists to fence.
     *
     * NOTE WHAT THAT BRINGS WITH IT: the bare `'->save()'` needle below applies to every scanned
     * file, so every persistence call in `RotaImport` must be `create()`/`update()`. In practice it
     * makes NO persistence call at all — it persists only through `App\Support\Rota\RotaAssignment`
     * and `App\Support\Rota\VacationBooking` (Decision F) — which is the strongest available way to
     * satisfy the needle, and is worth stating rather than leaving for somebody to rediscover.
     */
    private const SCANNED_FILES = [
        'app/Http/Controllers/Admin/PersonController.php',
        'app/Http/Controllers/Admin/PromotionController.php',
        'app/Http/Controllers/Admin/RosterImportController.php',
        'app/Support/Roster/RosterImport.php',
        'app/Support/Rota/RotaImport.php',
    ];

    /**
     * THE 2026-08-12 SWEEP (ruling 66). Twenty writer shapes were planted against this list and
     * ELEVEN walked through. The bare `->save()` needle carries more of this guard than it looks —
     * it catches property-assign, `forceFill([...])->save()` and `$person->user()->save($u)`
     * alike, which is why `RotaImport` making NO persistence call at all is stated above as the
     * strongest way to satisfy it. What it does NOT catch is every shape that persists WITHOUT
     * `save()`: `User::query()->create(`, `User::insert(`, `User::upsert(`, `User::destroy(` and
     * the relation verbs other than `create`. Those are added below.
     *
     * MEASURED, per ruling 42, over the five scanned files: every new needle matched ZERO. This
     * guard has no allow-list at all — Decision I says none should exist — so a needle that named
     * one of these five would have to be answered by changing the FILE, which is the point.
     *
     * WITHDRAWN ON MEASUREMENT: bare `User::query(`. It matches `PersonController` — inside a
     * DOCBLOCK, explaining why `User::query()->join('people', …)` cannot show unbound accounts.
     * This guard does not strip comments (unlike `RotaAccessTest` and `DemoRowsAreLedgeredTest`),
     * so it would fail the build on prose describing the system correctly, and the only remedies
     * would be an allow-list this guard is designed not to have, or deleting the explanation.
     * That is trap 1 exactly. The verb-qualified form below reaches the shape without it.
     *
     * RESIDUALS. No substring reaches these:
     *   - `User::query()->where(...)->delete()` — a `where` between the model and the verb.
     *   - `$u->delete()` on an already-bound instance.
     * Neither MINTS a credential, which is what Decision I is about, so both are noted rather
     * than chased.
     */
    private const NEEDLES = [
        'User::create(',
        "DB::table('users')",
        'DB::table("users")',
        // Added by the 2026-08-12 sweep: the shapes that persist a `users` row without ever
        // reaching `->save()` or `User::create(`.
        'User::insert(',
        'User::upsert(',
        'User::destroy(',
        'User::truncate(',
        'User::query()->create(',
        'User::query()->insert(',
        'User::query()->firstOrCreate(',
        'User::query()->updateOrCreate(',
        'User::query()->upsert(',
        // `Person::user()` is SINGULAR (at most one account per person, `users.person_id`
        // UNIQUE) — `->users()->create(` can never match real code and was a dead needle;
        // review finding (minor) 7, the reviewer's own mutation test proved it. Kept alongside
        // the correct one rather than removed, in case the relation is ever renamed back.
        '->users()->create(',
        '->user()->create(',
        // The rest of the relation surface. `->user()->create(` was needled and its siblings were
        // not, which is the arbitrary line through one API that review F8 objected to next door.
        // `->user()->save($u)` is reached today by the bare `->save()` needle below; the others
        // are not reached by anything.
        '->user()->insert(',
        '->user()->updateOrCreate(',
        '->user()->firstOrCreate(',
        '->user()->upsert(',
        '->user()->saveMany(',
        'new User(',
        'User::forceCreate(',
        'User::updateOrCreate(',
        'User::firstOrCreate(',
        '->save()',
    ];

    public function test_none_of_the_four_files_writes_to_users(): void
    {
        $offenders = [];

        foreach (self::SCANNED_FILES as $relative) {
            $contents = (string) File::get(base_path($relative));

            foreach (self::NEEDLES as $needle) {
                if (str_contains($contents, $needle)) {
                    $offenders[] = $relative.' contains '.$needle;
                }
            }
        }

        $this->assertSame([], $offenders,
            "The invitation flow is the ONLY path from a roster entry to a credential (Decision I).\n"
            .implode("\n", $offenders));
    }

    /** A stale file list is a silently disabled guard. */
    public function test_every_scanned_file_still_exists(): void
    {
        foreach (self::SCANNED_FILES as $relative) {
            $this->assertFileExists(base_path($relative), "Scanned file {$relative} is gone — prune the list.");
        }
    }

    /**
     * THE VACUITY TWIN (2026-08-12 sweep, ruling 66), and it has to be built differently from
     * every sibling's. This guard is INVERTED — its subjects must match NOTHING — so there is no
     * control writer to point at, and `assertSame([], $offenders)` over five files that never
     * mention `User` at all would pass against a needle list of pure nonsense. Review minor 7
     * proved this list against a mutation once, by hand, and left nothing behind that would prove
     * it again.
     *
     * So it is pinned against the real minting paths INSTEAD: the two files in this codebase that
     * legitimately create an account must each match a needle here. If they stop matching, either
     * an account is now minted in a spelling this guard cannot see, or the list has rotted — and
     * both are the same failure from this guard's point of view. Neither file is in
     * `SCANNED_FILES`, so this can never conflict with the sweep above.
     */
    public function test_the_needles_name_the_real_minting_paths(): void
    {
        foreach ([
            'app/Console/Commands/CreateAdmin.php',
            'app/Http/Controllers/Auth/InvitationAcceptController.php',
        ] as $minter) {
            $source = (string) File::get(base_path($minter));

            $matched = array_values(array_filter(
                self::NEEDLES,
                static fn (string $needle): bool => str_contains($source, $needle),
            ));

            $this->assertNotSame([], $matched,
                $minter.' mints an account and matches none of this guard\'s needles, so the '
                .'needles describe a shape nothing in the tree uses — the scan above would stay '
                .'green against a roster file minting an account the same way this one does.');
        }
    }
}
