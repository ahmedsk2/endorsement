<?php

namespace Tests\Feature\Build;

use Illuminate\Support\Facades\File;
use Tests\TestCase;

/**
 * Finding 1, made permanent. `App\Support\Calendar` memoises its settings and its holiday list
 * in statics for the life of the process; `flush()` had NO production caller before P1b, which
 * was harmless only while nothing edited calendar configuration at runtime.
 *
 * Any file under app/, database/ or routes/ that touches the institution's calendar columns or
 * a holiday must also call `Calendar::flush()` somewhere in the SAME file — a coarse, file-level
 * signal, deliberately (the feature tests — `CalendarSettingsTest`, `HolidayCrudTest` — prove
 * per-request correctness; this is defence in depth so "a seventh writer added later cannot
 * forget", per finding 1's own words). Same discipline as `CalendarIsTheOnlyConverterTest` and
 * `InstitutionProvenanceTest`, both of which scan app/+database/+routes/, not app/ alone, since
 * a migration, seeder or console command is a live surface too (`InstitutionProvenanceTest`'s
 * own docblock: "P1a Task 4 and Task 7 each nearly shipped one; both were caught by luck").
 *
 * WRITE_NEEDLES is not writer-only by construction — `Institution::current()` in particular is
 * also how `App\Support\Calendar` itself reads the row to BUILD its own memo, and how two
 * console commands (`CreateAdmin`, `InstanceShow`) read it for an unrelated display. Each
 * incidental match is excused on ALLOW_LIST with the reason stated at its site, the same
 * convention `CalendarIsTheOnlyConverterTest::STRTOTIME_ALLOW_LIST` uses — verified empirically
 * against the real tree (a plain grep for these four needles across app/+database/+routes/
 * before writing this list), not guessed.
 */
class CalendarWritersFlushTest extends TestCase
{
    /** Every current match for WRITE_NEEDLES that is NOT itself required to flush, with why. */
    private const ALLOW_LIST = [
        // The module BEING flushed, not a writer: Calendar::settings() reads
        // Institution::current() and Calendar::activeHolidays() reads Holiday::query() to BUILD
        // the memo; Calendar::flush() is DEFINED here, never called on itself.
        'app/Support/Calendar.php',
        // Reads Institution::current() only — attaches the bootstrap admin's institution_id and
        // prints the institution's code in a confirmation message. Never mutates the row.
        'app/Console/Commands/CreateAdmin.php',
        // Reads Institution::current() only, for a diagnostic status display. Never mutates it.
        'app/Console/Commands/InstanceShow.php',
        // Writes hijri_offset_days via Institution::firstOrNew()->save(), on CREATE or when
        // HIJRI_OFFSET_DAYS is explicitly configured. The seeder runs as its own process and
        // exits — nothing renders from the stale in-process memo afterward.
        'database/seeders/ReferenceSeeder.php',
        // Reads Institution::current() only, to compare against the submitted payload for
        // Decision D's hard-lock (period_type/academic_year_start freeze once periods exist).
        // Never saves the row — CalendarSettingsController::update() is the writer, and it does
        // flush.
        'app/Http/Requests/Admin/CalendarSettingsRequest.php',
        // Reads and writes `institutions.contact_visibility` — a PE-02 policy column, not a
        // calendar one. Calendar::settings() memoises the six calendar values as an array, not
        // the model, so there is nothing stale here for flush() to clear. Matched only because
        // WRITE_NEEDLES includes `Institution::current()`, which any reader of that row calls.
        'app/Support/ContactVisibility.php',
        // Reads and writes `institutions.name`, and reads `institutions.code` to render it
        // read-only — display columns, not calendar ones, exactly as ContactVisibility above
        // is a policy column. Calendar::settings() memoises the six calendar values as an
        // array and holds neither, so flushing on a rename would imply a relationship that
        // does not exist. Matched only because WRITE_NEEDLES includes `Institution::current()`.
        'app/Http/Controllers/Admin/DepartmentProfileController.php',
        // Reads the institution row and counts holiday rules to REPORT them on ST-01's derived
        // setup checklist, and writes nothing at all — DepartmentSetupTest snapshots every table
        // in the schema around a call and pins that. The same read-only carve-out CreateAdmin
        // and InstanceShow above already hold; there is nothing here for a flush to clear.
        'app/Support/Setup/DepartmentSetup.php',
    ];

    /** Tokens that mean "this file reads or writes calendar configuration". */
    private const WRITE_NEEDLES = [
        'Institution::current()',
        'Institution::firstOrNew(',
        'Holiday::create(',
        'Holiday::query()',
    ];

    /** @return list<\SplFileInfo> */
    private function phpFilesUnderAppDatabaseAndRoutes(): array
    {
        $files = [];

        foreach ([app_path(), base_path('database'), base_path('routes')] as $dir) {
            if (File::exists($dir)) {
                $files = array_merge($files, File::allFiles($dir));
            }
        }

        return $files;
    }

    private function relativePath(\SplFileInfo $file): string
    {
        return str_replace('\\', '/', str_replace(base_path().DIRECTORY_SEPARATOR, '', $file->getPathname()));
    }

    private function matchesAnyNeedle(string $contents): bool
    {
        foreach (self::WRITE_NEEDLES as $needle) {
            if (str_contains($contents, $needle)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Assert over the whole SET, never inside a foreach that can silently stop guarding once
     * the last offender is fixed (the same failure mode `CompiledCssIsLightOnlyTest` documents).
     */
    public function test_every_calendar_writer_flushes_the_memo(): void
    {
        $offenders = [];

        foreach ($this->phpFilesUnderAppDatabaseAndRoutes() as $file) {
            if ($file->getExtension() !== 'php') {
                continue;
            }

            $relative = $this->relativePath($file);

            if (in_array($relative, self::ALLOW_LIST, true)) {
                continue;
            }

            $contents = (string) file_get_contents($file->getPathname());

            if ($this->matchesAnyNeedle($contents) && ! str_contains($contents, 'Calendar::flush()')) {
                $offenders[] = $relative;
            }
        }

        $this->assertSame(
            [],
            $offenders,
            "Finding 1: a file that reads/writes institutions' calendar columns or a holiday must ".
            'also call Calendar::flush() somewhere in the same file (or be added to ALLOW_LIST '.
            "with a stated reason), or a save in this request can render from the pre-save memo. ".
            "Offending files:\n".implode("\n", $offenders)
        );
    }

    /**
     * The other direction: every entry on the allow-list must still be earning its place — same
     * discipline as `CalendarIsTheOnlyConverterTest::test_every_allow_listed_file_still_calls_strtotime`
     * and `InstitutionProvenanceTest::test_the_backfill_allow_list_is_not_stale`.
     */
    public function test_the_allow_list_is_not_stale(): void
    {
        $stale = [];

        foreach (self::ALLOW_LIST as $relative) {
            $path = base_path($relative);
            $contents = file_exists($path) ? (string) file_get_contents($path) : '';

            if (! file_exists($path) || ! $this->matchesAnyNeedle($contents)) {
                $stale[] = $relative;
            }
        }

        $this->assertSame(
            [],
            $stale,
            'These allow-listed files no longer match any WRITE_NEEDLE (or no longer exist) — '
            .'remove them from ALLOW_LIST: '.implode(', ', $stale)
        );
    }
}
