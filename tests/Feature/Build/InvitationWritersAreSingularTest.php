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
 * `tests/` and `database/factories/` are NOT scanned — the same carve-out
 * `PersonLevelsHaveOneWriterTest` states: a fixture seeding rows for its own test's purposes is not
 * the production integrity surface this guard exists to close.
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

    private const NEEDLES = [
        'Invitation::issue(',
        'Invitation::create(',
        'Invitation::insert(',
        'Invitation::updateOrCreate(',
        'Invitation::firstOrCreate(',
        '->invitations()->create(',
        "DB::table('invitations')",
        'DB::table("invitations")',
        "'revoked_at' =>",
        "'accepted_at' =>",
        "'revoked_by_user_id' =>",
        "'invited_by_user_id' =>",
        // DELIBERATELY ABSENT: `'expires_at' =>` and `'token_hash' =>`. Both are real columns of
        // this table and both are also columns of tables that have nothing to do with it
        // (`login_otps`, `email_otps`, `trusted_devices`), so either needle would buy three
        // allow-list entries for files this guard has no business naming — and an allow-list
        // carrying files that need no entry is the stale-allow-list failure the companion test
        // below exists to catch, installed on purpose.
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
