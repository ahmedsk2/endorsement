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
        // The one writer of vacations (P1d Decision F).
        'app/Support/Rota/VacationBooking.php',
        // Factories populating fixture rows for OTHER tests are not production writers — same
        // carve-out PersonLevelsHaveOneWriterTest makes for PersonLevelFactory.
        'database/factories/MasterRotaAssignmentFactory.php',
        'database/factories/VacationFactory.php',
    ];

    /**
     * THE `$model->update([...])` SHAPE, added after `ClinicWritersAreSingularTest` was found blind
     * to it (P1e-1 adversarial review finding 2) and this guard was probed for the same hole. It
     * had it. MEASURED HERE THE SAME WAY, on a throwaway file under `app/` that rewrote
     * `starts_on`, `ends_on`, `period_id`, `granularity` and `source` across BOTH tables through
     * nothing but `update([...])`: every needle below was absent and this test stayed GREEN. An
     * endpoint could have re-dated every span in the department without touching `RotaAssignment`
     * or `VacationBooking`, and the build would have passed.
     *
     * That is not a hypothetical shape here — `->update([...])` is how `UnitController`,
     * `LevelController` and `HolidayController` each write their own `setActive()`, so it is what a
     * "just nudge this span" endpoint written beside them reaches for first. And it is the shape
     * that most directly defeats what these two writers are FOR: `RotaAssignment` refuses
     * overlapping spans before it writes, and an `update(['starts_on' => ...])` walks one span's
     * start date straight over its neighbour's end with no guard anywhere in the path.
     *
     * MEASURED, per ruling 42, over app/ + database/ + routes/ BEFORE anything was added. Every
     * needle below: ZERO pre-existing matches, in both quote styles. No allow-list entry was
     * bought.
     *
     * WITHDRAWN ON MEASUREMENT, not on taste:
     *   - `->update(['unit_id'` matches `app/Support/UnitMerge.php`, twice — re-pointing
     *     `handovers` and `unit_field_definitions`, neither of them a rota table. That single
     *     incidental match would buy an allow-list entry for the one file that must one day
     *     re-point `master_rota_assignments.unit_id`: `UnitMerge` re-points four unit-owned tables
     *     today and this is not among them. Allow-listing it blinds this guard on precisely the
     *     file the next real offender arrives in — the identical trap
     *     `ClinicWritersAreSingularTest` withdrew `->update(['active'` over.
     *   - `->update(['person_id'` matches the D3 reversal migration, and `person_id` is carried by
     *     `invitations`, `person_levels`, `users` and four fields of `handover_signoffs` besides —
     *     `AccountLinkHasOneWriterTest` states the same objection to the same column name.
     *   - `->update(['institution_id'` matches the institution backfill migration. D11 makes that
     *     column provenance on nearly every table; it identifies no table at all.
     *   - `$span->update(` is ZERO today but was withdrawn anyway: `$span` names a `PersonLevel` in
     *     `Person.php` and `PersonPresenter`, and a rota span under `app/Support/Rota/`. Whichever
     *     of the two guards claims it fires wrongly on the other's writes.
     *   - `$row->update(` — a general-purpose local throughout this codebase, so the needle is
     *     fragile toward FALSE positives rather than cheap. `$assignment` and `$vacation` name
     *     these two models and nothing else in the tree (`MasterRotaController` binds
     *     `Vacation $vacation` by route-model binding, which is exactly why that spelling is the
     *     one a future edit endpoint would use).
     *
     * WHAT THE COLUMN-QUALIFIED HALF CANNOT SEE, per the same observation the clinic guard records:
     * it matches only the FIRST key of the array, so `update(['unit_id' => 2, 'starts_on' => ...])`
     * fires nothing — `unit_id` is withdrawn above. The variable-qualified half is what covers
     * that, whatever column comes first, and the two halves failing for different reasons is why
     * both are kept. The residual gap is a write to `unit_id` or `institution_id`, first in the
     * array, through a variable named neither `$assignment` nor `$vacation` — stated rather than
     * implied.
     *
     * `starts_on` and `ends_on` are also columns of `periods`, so unlike the clinic guard's four
     * these are NOT unique to the tables here. Nothing edits a period's dates today (periods are
     * generated and deleted, never re-dated — `PeriodController` has `index`/`store`/`destroy` and
     * no update path at all), which is why the measurement comes back zero. If a period re-dating
     * screen ever arrives, the answer is to NARROW this needle, not to allow-list
     * `PeriodController` — that controller already refuses a delete while assignments reference the
     * year, which makes it a plausible future home for a real rota write.
     */
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
        // Column-qualified: catches the idiom whatever the variable is called. Every one of these
        // five columns is written by `RotaAssignment`/`VacationBooking` and by nothing else.
        "->update(['starts_on'",
        '->update(["starts_on"',
        "->update(['ends_on'",
        '->update(["ends_on"',
        "->update(['period_id'",
        '->update(["period_id"',
        "->update(['granularity'",
        '->update(["granularity"',
        "->update(['source'",
        '->update(["source"',
        // Variable-qualified: catches it whatever the COLUMN is, which is the only reach this
        // guard has over `unit_id`, `person_id` and `institution_id` — each of which is another
        // table's column too and so cannot be needled by name.
        '$assignment->update(',
        '$vacation->update(',
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
