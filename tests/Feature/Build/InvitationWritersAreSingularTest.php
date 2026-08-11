<?php

namespace Tests\Feature\Build;

use Illuminate\Support\Facades\File;
use Tests\TestCase;

/**
 * P1c-2 Decision C: `App\Support\Invitations\InvitationIssue` is the ONLY writer of `invitations`.
 * Same species as `RotaWritersAreSingularTest`, `PersonLevelsHaveOneWriterTest` and
 * `PersonActiveHasOneWriterTest` — a deliberately coarse substring scan whose job is to be a
 * stop-sign a future change trips over, not a static analyser.
 *
 * WHY THIS TABLE NEEDS ONE. An invitation is a bearer credential: whoever holds the link creates
 * the account it names. Two properties hold only because a single path enforces them — at most one
 * live link exists per person at any moment (the supersede pass), and the whole superseded set is
 * authorized under `ManagerScope` BEFORE anything is mutated. Finding 3 recorded what the tree
 * looked like without this guard: `accepted_at`/`revoked_at` were written at three separate
 * controller sites via `forceFill([...])->save()`, and a fourth was one resend away.
 *
 * `tests/` IS NOT SCANNED; `database/` IS, factories included (review F9). The docblock here used
 * to claim otherwise, copying a sentence from `PersonLevelsHaveOneWriterTest` that was about that
 * guard's ALLOW-LIST ENTRY rather than about its scan roots — and the code never agreed with it,
 * because `base_path('database')` contains `factories/`. The code is what is right, and the two
 * established siblings settle it: `PersonLevelsHaveOneWriterTest` and `RotaWritersAreSingularTest`
 * both scan factories and both name the offending factory in the allow-list with a reason. A
 * factory that writes the column directly IS a second writer of the shape this guard is about; it
 * is merely one whose blast radius stops at the suite. Naming it costs one line and makes the
 * exemption reviewable, where excluding the directory makes every future factory exempt in
 * advance, invisibly. `tests/` stays out because a guard that named its own fixtures would be
 * unusable.
 */
class InvitationWritersAreSingularTest extends TestCase
{
    /** Every file allowed to write `invitations`, with why. */
    private const ALLOW_LIST = [
        // The one writer: mint, supersede, rotate (Decision C).
        'app/Support/Invitations/InvitationIssue.php',
        // The model's own `$fillable`, `casts()` and the mint the writer calls.
        'app/Models/Invitation.php',
        // `revoke()` — an explicit administrator act on ONE named invitation, not an issue path,
        // and the endpoint the "kill this link" affordance posts to. It carries its own
        // `ManagerScope` gate and its own audit row.
        'app/Http/Controllers/Admin/InvitationController.php',
        // The claim itself stamps `accepted_at` inside the redemption transaction, under
        // `lockForUpdate()`. Redemption is not issuance and must not route through the issuer.
        'app/Http/Controllers/Auth/InvitationAcceptController.php',
    ];

    /**
     * THREE WRITER SHAPES THIS SET USED TO MISS ENTIRELY (review F8). The original needles caught
     * `Model::create(`, `DB::table(` and the array-literal payload, which is one way of three to
     * write a row:
     *
     *  - PROPERTY ASSIGNMENT then `->save()`. `$invitation->revoked_at = now();` matches no
     *    array-key needle at all, and it is the shape `revoke()` is one refactor away from —
     *    finding 3 recorded three sites already writing `accepted_at`/`revoked_at` through
     *    `forceFill([...])->save()`, and dropping the `forceFill` is how that becomes invisible.
     *  - RELATION `updateOrCreate`/`firstOrCreate`/`save`. `->invitations()->create(` was covered
     *    and its four siblings were not, which is an arbitrary line through one API.
     *  - `find()` FOLLOWED BY `destroy()`/`delete()`. Deleting an invitation is not a supported
     *    act at all — a superseded row is KEPT, with `revoked_at` set, because the claim-status
     *    projection reads it — so the destructive shapes are needles rather than allow-list
     *    candidates.
     *
     * Each new needle was proved by PLANTING a writer of exactly its shape and watching this test
     * name the file, then reverting. A needle nobody has seen fail is a comment.
     *
     * The `= ` trailing space on every property needle is load-bearing, the same way
     * `AccountLinkHasOneWriterTest` records for `->person_id = `: without it `->revoked_at` also
     * matches `->revoked_at === null`, which is a READ that `InvitationStatus` and `isOpen()` both
     * perform legitimately.
     */
    private const NEEDLES = [
        'Invitation::issue(',
        'Invitation::create(',
        'Invitation::insert(',
        'Invitation::updateOrCreate(',
        'Invitation::firstOrCreate(',
        'Invitation::destroy(',
        'Invitation::truncate(',
        // The front half of `find()->delete()`. Every legitimate single-row read in this codebase
        // arrives by route-model binding or through `InvitationIssue`/`InvitationStatus`, so
        // nothing needs this today and a future caller that thinks it does should be named here.
        'Invitation::find(',
        '->invitations()->create(',
        '->invitations()->insert(',
        '->invitations()->updateOrCreate(',
        '->invitations()->firstOrCreate(',
        '->invitations()->save(',
        '->invitations()->update(',
        '->invitations()->delete(',
        "DB::table('invitations')",
        'DB::table("invitations")',
        "'revoked_at' =>",
        "'accepted_at' =>",
        "'revoked_by_user_id' =>",
        "'invited_by_user_id' =>",
        '->revoked_at = ',
        '->accepted_at = ',
        '->revoked_by_user_id = ',
        '->invited_by_user_id = ',
        // DELIBERATELY ABSENT: `'expires_at' =>` and `'token_hash' =>` (and their property
        // twins). All are real columns of this table and all are also columns of tables that have
        // nothing to do with it (`login_otps`, `email_otps`, `trusted_devices`), so any of them
        // would buy three allow-list entries for files this guard has no business naming — and an
        // allow-list carrying files that need no entry is the stale-allow-list failure the
        // companion test below exists to catch, installed on purpose.
        //
        // ALSO ABSENT, and honestly so: `$invitation->delete()` on an already-bound instance. No
        // substring distinguishes it from every other model's `->delete()`, so this guard does not
        // see it. `Invitation::destroy(`, `Invitation::find(` and the relation shapes above cover
        // every route TO such an instance that does not go through route-model binding, which is
        // as far as a substring scan reaches. Stated rather than implied: the gap is the reason
        // `InvitationController` is allow-listed by name rather than trusted.
    ];

    public function test_only_the_invitation_writer_writes_invitations(): void
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
            "`invitations` must be written only by App\\Support\\Invitations\\InvitationIssue "
            ."(P1c-2 Decision C).\n".implode("\n", $offenders));
    }

    /** A stale allow-list is a silently disabled guard. */
    public function test_every_allow_listed_file_still_exists(): void
    {
        foreach (self::ALLOW_LIST as $relative) {
            $this->assertFileExists(base_path($relative), "Allow-listed file {$relative} is gone — prune the list.");
        }
    }
}
