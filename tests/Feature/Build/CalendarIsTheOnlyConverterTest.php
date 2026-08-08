<?php

namespace Tests\Feature\Build;

use Illuminate\Support\Facades\File;
use Tests\TestCase;

/**
 * Munawib AR-08, stricter reading (Decision A): nothing outside `App\Support\Calendar`
 * converts a date. Same species of guard as `CompiledCssIsLightOnlyTest` — a regression this
 * class exists to catch is silent by nature (a stray `strtotime()` still "works" until a
 * timezone or a day boundary makes it wrong), so it is enforced here rather than trusted to
 * review.
 */
class CalendarIsTheOnlyConverterTest extends TestCase
{
    private const CALENDAR_FILE = 'app/Support/Calendar.php';

    /**
     * Every file under app/ currently allowed to call strtotime(), and why. Task 2 does not
     * remove any of these — it only proves the module and guards against new ones appearing.
     * Task 5 shrinks this list to LegacyImport.php alone; removing an entry here is meant to
     * be a deliberate edit against that task, never a silent widening.
     *
     * Reconnaissance finding 2 (plan doc) named EndorsementController and LegacyImport as the
     * live date-conversion paths outside a module. Writing this guard against the actual tree
     * turned up two more calls it did not enumerate — LegacyReconcile and Plausibility — both
     * bloc-adjacent to the one-way legacy import, not general application date handling.
     * Recorded in the plan's Amendments section.
     */
    private const STRTOTIME_ALLOW_LIST = [
        // EndorsementController::normalizeDate()/parseDateOrToday() — pre-existing implicit
        // converter AR-08 forbids in principle; absorbed into Calendar in a later P1a task.
        'app/Http/Controllers/EndorsementController.php',
        // The one-way legacy import itself. Reads a foreign system's date strings, which are
        // NOT guaranteed Y-m-d — Calendar::parse() would throw on legacy row shapes. Stays
        // outside the module deliberately (Task 5 is the last entry standing here).
        'app/Console/Commands/LegacyImport.php',
        // Diagnostic reconciliation for the same one-way legacy import (counts unparseable
        // legacy date headers) — same foreign-format justification as LegacyImport.php above,
        // never runs against live application data.
        'app/Console/Commands/LegacyReconcile.php',
        // Plausibility::plausibleDates() compares two legacy admission/discharge strings for
        // ordering during import validation — a boolean comparison over foreign-format
        // strings, not a conversion feeding a screen.
        'app/Support/Plausibility.php',
    ];

    /** @return list<\SplFileInfo> */
    private function phpFilesUnderApp(): array
    {
        return File::allFiles(app_path());
    }

    private function relativePath(\SplFileInfo $file): string
    {
        return str_replace('\\', '/', str_replace(base_path().DIRECTORY_SEPARATOR, '', $file->getPathname()));
    }

    /**
     * Assert over the whole SET, never inside a foreach that can silently stop guarding once
     * the last offender is fixed (CompiledCssIsLightOnlyTest.php:44-52 writes up that failure
     * mode).
     */
    public function test_no_intl_calendar_symbols_appear_outside_the_calendar_module(): void
    {
        $needles = ['IntlCalendar', 'IntlDateFormatter', 'islamic-umalqura'];

        $offenders = [];

        foreach ($this->phpFilesUnderApp() as $file) {
            $relative = $this->relativePath($file);

            if ($relative === self::CALENDAR_FILE) {
                continue;
            }

            $contents = (string) file_get_contents($file->getPathname());

            foreach ($needles as $needle) {
                if (str_contains($contents, $needle)) {
                    $offenders[] = "{$relative} contains \"{$needle}\"";
                }
            }
        }

        $this->assertSame(
            [],
            $offenders,
            "AR-08: only ".self::CALENDAR_FILE." may reference Hijri/ICU calendar symbols. Found:\n"
            .implode("\n", $offenders)
        );
    }

    public function test_strtotime_appears_only_on_the_allow_list(): void
    {
        $offenders = [];

        foreach ($this->phpFilesUnderApp() as $file) {
            $relative = $this->relativePath($file);

            // Calendar.php's own docblock names strtotime() in prose, explaining the exact
            // leniency trap it replaces — a mention, not a call. Same carve-out as the
            // IntlCalendar check above.
            if ($relative === self::CALENDAR_FILE || in_array($relative, self::STRTOTIME_ALLOW_LIST, true)) {
                continue;
            }

            $contents = (string) file_get_contents($file->getPathname());

            if (str_contains($contents, 'strtotime(')) {
                $offenders[] = $relative;
            }
        }

        $this->assertSame(
            [],
            $offenders,
            "AR-08: strtotime() is an implicit, un-audited date converter. New callers must go ".
            'through App\\Support\\Calendar instead. Found strtotime() in: '.implode(', ', $offenders)
        );
    }

    /**
     * The other direction: every entry on the allow-list must still be earning its place.
     * Prevents the list growing stale in the other direction — an entry nobody needs any more
     * silently widening the guard's blind spot forever.
     */
    public function test_every_allow_listed_file_still_calls_strtotime(): void
    {
        $stale = [];

        foreach (self::STRTOTIME_ALLOW_LIST as $relative) {
            $path = base_path($relative);

            if (! file_exists($path) || ! str_contains((string) file_get_contents($path), 'strtotime(')) {
                $stale[] = $relative;
            }
        }

        $this->assertSame(
            [],
            $stale,
            'These allow-listed files no longer call strtotime() (or no longer exist) — remove '
            .'them from STRTOTIME_ALLOW_LIST: '.implode(', ', $stale)
        );
    }
}
