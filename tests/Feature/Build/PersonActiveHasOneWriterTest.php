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
    ];

    private const NEEDLES = [
        "\$person->update(['active'",
        '$person->update(["active"',
        "->person?->update(['active'",
        '->person?->update(["active"',
        "->person->update(['active'",
        '->person->update(["active"',
        "DB::table('people')",
        'DB::table("people")',
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
}
