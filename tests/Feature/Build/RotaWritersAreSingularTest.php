<?php

namespace Tests\Feature\Build;

use Illuminate\Support\Facades\File;
use Tests\TestCase;

/**
 * P1d Decision F: `App\Support\Rota\RotaAssignment` is the ONLY writer of `master_rota_assignments`
 * and `App\Support\Rota\VacationBooking` is the ONLY writer of `vacations`. Same species as
 * `PersonLevelsHaveOneWriterTest`, `PersonActiveHasOneWriterTest` and `CsvIsTheOnlyReaderWriterTest`
 * — one guard file covering BOTH needle families deliberately, per Decision F: two files asserting
 * the same shape over the same directories would be the duplication the pattern exists to prevent.
 *
 * A rota row (or a vacation row) written by any other path defeats the containment/overlap
 * invariant each model's `booted()` guard enforces — the writer is where "refuse before writing"
 * lives; a second insertion path bypasses it entirely.
 *
 * `tests/` is NOT scanned — same reasoning `PersonLevelsHaveOneWriterTest` states: a test fixture
 * seeding rows directly for an unrelated test's own purposes is not the production integrity
 * surface this guard exists to close.
 */
class RotaWritersAreSingularTest extends TestCase
{
    /** Every file allowed to write `master_rota_assignments` or `vacations`, with why. */
    private const ALLOW_LIST = [
        // The one writer of master_rota_assignments (P1d Decision F).
        'app/Support/Rota/RotaAssignment.php',
        // A factory populating fixture rows for OTHER tests is not a production writer — same
        // carve-out PersonLevelsHaveOneWriterTest makes for PersonLevelFactory.
        'database/factories/MasterRotaAssignmentFactory.php',
    ];

    private const NEEDLES = [
        'MasterRotaAssignment::create(',
        'MasterRotaAssignment::insert(',
        'MasterRotaAssignment::updateOrCreate(',
        "DB::table('master_rota_assignments')",
        'DB::table("master_rota_assignments")',
        'Vacation::create(',
        'Vacation::insert(',
        'Vacation::updateOrCreate(',
        "DB::table('vacations')",
        'DB::table("vacations")',
    ];

    public function test_only_the_rota_writers_write_the_rota_tables(): void
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
            'master_rota_assignments must be written only by App\\Support\\Rota\\RotaAssignment and '
            ."vacations only by App\\Support\\Rota\\VacationBooking (P1d Decision F).\n"
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
