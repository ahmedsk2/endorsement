<?php

namespace Tests\Feature\Build;

use Illuminate\Support\Facades\File;
use Tests\TestCase;

/**
 * P1c-2 Decision E: `App\Support\AccountUnbind` is the ONLY thing that clears or reassigns the
 * link between an account and its person. Same species as `RotaWritersAreSingularTest`,
 * `InvitationWritersAreSingularTest` and `PersonActiveHasOneWriterTest` — a deliberately coarse
 * substring scan whose job is to be a stop-sign a future change trips over, not a static analyser.
 *
 * WHY THIS COLUMN NEEDS ONE. Clearing the link is not one write, it is three facts that must
 * travel together:
 *
 *  - `$user->full_name` and `$user->position` are read-through accessors onto the linked Person
 *    (P0c), so an account whose link is cleared but which stays ACTIVE is nameless and
 *    positionless on every screen, with no error anywhere. Deactivation is not a courtesy that
 *    accompanies the unbind; it is half of it.
 *  - `handover_signoffs.signed_off_by_name` is null on every row written before 2026-07-27 (the
 *    freeze migration deliberately backfilled nothing) and the sheet falls back to the live
 *    relation, so clearing the link without snapshotting first blanks the signer's name on
 *    medico-legal evidence.
 *  - The capability cache holds the account's resolved key set for CACHE_TTL.
 *
 * A second writer gets one of the three right and looks correct in review.
 *
 * `tests/` IS NOT SCANNED; `database/` IS, factories included (review F9) — see
 * `InvitationWritersAreSingularTest`'s docblock for why the code was right and this prose was
 * wrong. Two established siblings settle it by example: `PersonLevelsHaveOneWriterTest` and
 * `RotaWritersAreSingularTest` both scan factories and both NAME the offending factory in the
 * allow-list, which is a reviewable exemption where excluding the directory is a blanket one.
 */
class AccountLinkHasOneWriterTest extends TestCase
{
    /** Every file allowed to write the account/person link, with why. */
    private const ALLOW_LIST = [
        // The one writer: snapshot, clear, deactivate, flush, audit (Decision E).
        'app/Support/AccountUnbind.php',
        // Redemption. The claim inserts the `users` row that BINDS a person for the first time
        // — under `lockForUpdate()`, from the invitation's own locked row. Binding at account
        // creation is not the same act as unbinding a live one and must not route through it.
        'app/Http/Controllers/Auth/InvitationAcceptController.php',
        // `approve()` inserts a `users` row for a pending self-registration, binding it to the
        // matched-or-created person — the other, frozen creation path. Also the home of the
        // reactivation refusal, which READS the link.
        'app/Http/Controllers/Admin/UserManagementController.php',
        // The ONE exception CLAUDE.md already documents: one-way, read-only against its source,
        // idempotent, owner-run only against production, never a live application write path.
        // It raw-upserts `users` rows for historical members and resolves person ids for them.
        'app/Console/Commands/LegacyImport.php',
        // D3's reversal migration: the ONE-TIME backfill that gave `users` the column at all and
        // filled it, one person per pre-existing account. There is no writer to call yet at this
        // point in the schema's own history.
        'database/migrations/2026_08_10_120001_create_people_and_link_users.php',
        // Backfills `institution_id` only (`whereNull('institution_id')->update([...])`) —
        // matched solely because the raw-builder needle cannot tell which column a query writes.
        'database/migrations/2026_08_11_120001_backfill_institution_on_identity_rows.php',
    ];

    /**
     * Deliberately NOT a bare `'person_id' =>`: that column name is also carried by
     * `invitations`, `master_rota_assignments`, `vacations`, `person_levels` and four fields of
     * `handover_signoffs`, so the bare needle would name a dozen files this guard has no business
     * naming and the allow-list would become the thing that fails.
     *
     * `->person_id = ` keeps its trailing space on purpose: without it the needle also matches
     * `->person_id === null`, which is a READ (the claim path and the reactivation refusal both
     * do it) and not the write this guard is about. That property was measured, not assumed —
     * the bare needle was tried and it named the readers — and review F8 asks that it survive
     * every later widening of this list, so it does.
     *
     * THE THREE SHAPES F8 FOUND MISSING, and what each means for THIS column:
     *
     *  - Property assignment was already covered by `->person_id = `, and is the reason that
     *    needle exists at all. Nothing to add.
     *  - RELATION WRITES. The link column lives on `users`, so it is reachable from either end:
     *    `$person->user()->create([...])` and its `updateOrCreate`/`firstOrCreate`/`save`
     *    siblings set it from the person's side, and `$user->person()->associate($person)` /
     *    `->dissociate()` set it from the account's — and `dissociate()` in particular is
     *    EXACTLY the unbind, written in one line, with no snapshot, no deactivation and no cache
     *    flush. It is the most plausible second writer this table will ever see.
     *  - `find()` THEN `destroy()`/`delete()`. Removing the account row removes the link with it,
     *    and account deletion was WITHDRAWN as a capability (owner ruling, 2026-07-19) — the
     *    route was deleted, not just the button. `User::destroy(` is how it comes back, and it
     *    matches no existing needle.
     *
     * Each was proved by planting a writer of exactly that shape and watching this test name the
     * file, then reverting.
     *
     * `User::find(` WAS TRIED AND WITHDRAWN, which is the other half of that measurement. It is
     * the front of `User::find($id)->delete()`, so on paper it completes the third shape — but run
     * against this tree it named four files, three of them auth challenge controllers resolving
     * the session's own pending user (a read this guard has no business touching) and the fourth
     * only because `AccessControlController` QUOTES the expression in a comment explaining an
     * array-shaped-query defect. That is both failure modes at once: an allow-list of files that
     * need no entry, and a guard that fails on prose describing a bug it is not about. The
     * unspelt-out gap is stated instead: `$user->delete()` on a bound instance is invisible to a
     * substring scan, here as everywhere.
     */
    private const NEEDLES = [
        "'person_id' => null",
        '"person_id" => null',
        '->person_id = ',
        "forceFill(['person_id'",
        'forceFill(["person_id"',
        "->update(['person_id'",
        '->update(["person_id"',
        // From the account's side. `associate()`/`dissociate()` write the FK directly and are the
        // one-line spelling of this whole writer.
        '->person()->associate(',
        '->person()->dissociate(',
        // From the person's side. `->user()->create(` mints an account already bound to them.
        '->user()->create(',
        '->user()->insert(',
        '->user()->updateOrCreate(',
        '->user()->firstOrCreate(',
        '->user()->save(',
        '->user()->update(',
        '->user()->delete(',
        // Removing the account row takes the link with it, and account DELETION is a withdrawn
        // capability rather than an unimplemented one — these are how it returns.
        'User::destroy(',
        'User::truncate(',
        // A raw builder over the accounts table can write any column of it, including this one,
        // without matching any of the shapes above.
        "DB::table('users')",
        'DB::table("users")',
    ];

    public function test_only_the_unbind_writer_clears_or_reassigns_the_account_person_link(): void
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
            "The link between an account and its person must be written only by ".
            "App\\Support\\AccountUnbind and the two documented creation paths (P1c-2 Decision E).\n"
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
