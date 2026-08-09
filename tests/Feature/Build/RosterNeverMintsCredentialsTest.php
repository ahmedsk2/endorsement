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
    /** Decision I names these four files explicitly — no allow-list, because none should exist. */
    private const SCANNED_FILES = [
        'app/Http/Controllers/Admin/PersonController.php',
        'app/Http/Controllers/Admin/PromotionController.php',
        'app/Http/Controllers/Admin/RosterImportController.php',
        'app/Support/Roster/RosterImport.php',
    ];

    private const NEEDLES = [
        'User::create(',
        "DB::table('users')",
        '->users()->create(',
        'new User(',
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
}
