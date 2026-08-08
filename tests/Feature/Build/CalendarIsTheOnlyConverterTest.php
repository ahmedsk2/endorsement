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
     * Every file under app/ currently allowed to call strtotime(), and why.
     *
     * Reconnaissance finding 2 (plan doc) named EndorsementController and LegacyImport as the
     * live date-conversion paths outside a module. Writing this guard against the actual tree
     * turned up two more calls it did not enumerate — LegacyReconcile and Plausibility — both
     * bloc-adjacent to the one-way legacy import, not general application date handling.
     * Recorded in the plan's Amendments section.
     *
     * Task 5 (2026-08-08) absorbed EndorsementController::normalizeDate()/parseDateOrToday()
     * into Calendar and removed it from this list, per the plan's own text ("Task 5 shrinks
     * this list to LegacyImport.php alone"). The three entries below all remain: each reads a
     * FOREIGN system's date strings (a frozen legacy MySQL dump), not application dates — the
     * exact case Calendar::parse()'s Y-m-d-only strictness is not meant to police, since it
     * would throw on legacy row shapes that are not guaranteed Y-m-d.
     */
    private const STRTOTIME_ALLOW_LIST = [
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

    /**
     * Client-side date-construction needles Decision A forbids under `resources/js/`. Each one
     * is how the four now-deleted hand-rolled helpers (Index.vue's localYmd/datesBetween,
     * Sheet.vue's nextDate, Users.vue's fmt) did their own date arithmetic in the browser's
     * timezone — the exact `toISOString()` +03:00 midnight-rewind trap the design doc's Decision
     * A permanently kills by having the server send formatted labels and enumerated ranges
     * instead.
     *
     * `toLocaleDateString(`/`toLocaleTimeString(` were added because `toLocaleString(` — the
     * needle already here — does NOT substring-match either: "toLocaleDateString(" inserts
     * "Date" between "toLocale" and "String(", so the original needle silently missed the two
     * most natural client-side formatters. `Date.now(`, `Date.parse(`, `Date.UTC(`,
     * `Intl.DateTimeFormat` and `getTimezoneOffset(` cover the remaining built-in date-reading
     * and construction surface `new Date(`/`toISOString(` did not.
     *
     * @var list<string>
     */
    private const JS_DATE_NEEDLES = [
        'new Date(', 'toISOString(', 'toLocaleString(',
        'toLocaleDateString(', 'toLocaleTimeString(',
        'Date.now(', 'Date.parse(', 'Date.UTC(',
        'Intl.DateTimeFormat', 'getTimezoneOffset(',
    ];

    /**
     * Every file allowed to construct a date/time value directly (`Carbon::parse()`,
     * `CarbonImmutable::parse()`, `new DateTime`, `DateTime::createFromFormat`) instead of going
     * through `App\Support\Calendar` — the exact three sites Calendar's own docblock names
     * (Calendar.php:18-33) as deliberately outside the module.
     *
     * @var list<string>
     */
    private const CARBON_DATETIME_ALLOW_LIST = [
        // PHI `dob` (Calendar.php:29-31 / App\Casts\EncryptedDateTime's own docblock) — must
        // not route through the calendar; Calendar::parse() would throw on the ciphertext
        // marker this cast's getter can return.
        'app/Casts/EncryptedDateTime.php',
        // Byte-verbatim canonicalization (Calendar.php:25-27): `AuditChain::canonical()` v3
        // hashes the stored naive datetime verbatim precisely so no timezone can reinterpret
        // history. A timezone change must never re-render an already-hashed audit row.
        'app/Support/AuditChain.php',
        // A scheduler H:i TIME, not a calendar DATE — the cron dispatcher parses a
        // time-of-day for `Schedule::at()`, never a Y-m-d application date Calendar governs.
        'routes/console.php',
    ];

    /**
     * Carbon/DateTime construction needles Decision A forbids outside `App\Support\Calendar`.
     * Neither the ICU-symbol nor the strtotime() check above catches these — `Carbon::parse()`
     * is the single most natural way a Laravel developer would convert a date, and it silently
     * skips the instance timezone and department `hijriOffsetDays` Calendar applies.
     *
     * @var list<string>
     */
    private const CARBON_DATETIME_NEEDLES = [
        'Carbon::parse', 'CarbonImmutable::parse', 'new DateTime', 'DateTime::createFromFormat',
    ];

    /** @return list<\SplFileInfo> */
    private function phpFilesUnderApp(): array
    {
        return File::allFiles(app_path());
    }

    /**
     * I1 (InstitutionProvenanceTest) proved narrow scope is the recurring weakness in these
     * source-level guards: a migration or route closure is a live conversion surface too, not
     * just app/. The ICU-symbol and strtotime checks below scan all three for the same reason.
     *
     * @return list<\SplFileInfo>
     */
    private function phpFilesUnderAppDatabaseAndRoutes(): array
    {
        return $this->phpFilesUnder([app_path(), base_path('database'), base_path('routes')]);
    }

    /**
     * The Carbon/DateTime check's scope, per the reviewer's finding: app/ and routes/ only —
     * database/ is migrations, which build schema, not application date VALUES.
     *
     * @return list<\SplFileInfo>
     */
    private function phpFilesUnderAppAndRoutes(): array
    {
        return $this->phpFilesUnder([app_path(), base_path('routes')]);
    }

    /** @return list<\SplFileInfo> */
    private function phpFilesUnder(array $dirs): array
    {
        $files = [];
        foreach ($dirs as $dir) {
            if (File::exists($dir)) {
                $files = array_merge($files, File::allFiles($dir));
            }
        }

        return $files;
    }

    /** @return list<\SplFileInfo> */
    private function jsFilesUnderResources(): array
    {
        $dir = resource_path('js');

        return File::exists($dir) ? File::allFiles($dir) : [];
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

        foreach ($this->phpFilesUnderAppDatabaseAndRoutes() as $file) {
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

        foreach ($this->phpFilesUnderAppDatabaseAndRoutes() as $file) {
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

    /**
     * I2: `Carbon::parse()` is how a Laravel developer would most naturally convert a date —
     * the ICU-symbol check and the strtotime() check above needle for the wrong pattern for
     * this exact mistake and would let it ship green. Scope is app/ and routes/ per the
     * reviewer's finding (database/ is schema, not application date values).
     *
     * Assert over the whole SET, never inside a foreach that can silently stop guarding once
     * the last offender is fixed — same discipline as the two checks above.
     */
    public function test_carbon_and_datetime_construction_appears_only_on_the_allow_list(): void
    {
        $offenders = [];

        foreach ($this->phpFilesUnderAppAndRoutes() as $file) {
            $relative = $this->relativePath($file);

            // Calendar.php's own docblock explains, in prose, exactly why these three sites are
            // excused — a mention/necessary exception, not an unaudited conversion. Same
            // carve-out shape as the IntlCalendar and strtotime checks above.
            if ($relative === self::CALENDAR_FILE || in_array($relative, self::CARBON_DATETIME_ALLOW_LIST, true)) {
                continue;
            }

            $contents = (string) file_get_contents($file->getPathname());

            foreach (self::CARBON_DATETIME_NEEDLES as $needle) {
                if (str_contains($contents, $needle)) {
                    $offenders[] = "{$relative} contains \"{$needle}\"";
                }
            }
        }

        $this->assertSame(
            [],
            $offenders,
            'AR-08: Carbon::parse()/CarbonImmutable::parse()/new DateTime/DateTime::createFromFormat '
            .'bypass the instance timezone and department hijriOffsetDays that App\\Support\\Calendar '
            .'applies. New callers must go through Calendar instead. Found:'."\n".implode("\n", $offenders)
        );
    }

    /**
     * The other direction: every entry on CARBON_DATETIME_ALLOW_LIST must still be earning its
     * place, same discipline as test_every_allow_listed_file_still_calls_strtotime above.
     */
    public function test_every_carbon_datetime_allow_listed_file_still_needs_it(): void
    {
        $stale = [];

        foreach (self::CARBON_DATETIME_ALLOW_LIST as $relative) {
            $path = base_path($relative);
            $contents = file_exists($path) ? (string) file_get_contents($path) : '';

            $stillNeedsIt = false;
            foreach (self::CARBON_DATETIME_NEEDLES as $needle) {
                if (str_contains($contents, $needle)) {
                    $stillNeedsIt = true;
                    break;
                }
            }

            if (! $stillNeedsIt) {
                $stale[] = $relative;
            }
        }

        $this->assertSame(
            [],
            $stale,
            'These allow-listed files no longer contain any Carbon/DateTime construction needle '
            .'(or no longer exist) — remove them from CARBON_DATETIME_ALLOW_LIST: '.implode(', ', $stale)
        );
    }

    /**
     * Decision A, stricter than design §7: the server sends formatted labels and enumerated
     * date ranges; `resources/js` performs NO date arithmetic at all, ever — not even to
     * compute "today". Allow-list deliberately EMPTY: this is Task 6's whole point, and a
     * future PR reaching for `new Date()` needs a prop from the controller instead, not an
     * entry added here.
     *
     * Assert over the whole SET (not a foreach that stops guarding once the last offender is
     * fixed) — same discipline as the two PHP-side checks above.
     */
    public function test_no_client_side_date_construction_appears_under_resources_js(): void
    {
        $offenders = [];

        foreach ($this->jsFilesUnderResources() as $file) {
            $relative = str_replace('\\', '/', str_replace(base_path().DIRECTORY_SEPARATOR, '', $file->getPathname()));
            $contents = (string) file_get_contents($file->getPathname());

            foreach (self::JS_DATE_NEEDLES as $needle) {
                if (str_contains($contents, $needle)) {
                    $offenders[] = "{$relative} contains \"{$needle}\"";
                }
            }
        }

        $this->assertSame(
            [],
            $offenders,
            "Decision A: resources/js performs no date arithmetic — the server sends formatted ".
            "labels and enumerated ranges instead. Found:\n".implode("\n", $offenders)
        );
    }
}
