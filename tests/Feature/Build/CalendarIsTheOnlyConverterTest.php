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
     * Runtime dependencies a package under `packages/` may declare.
     *
     * DELIBERATELY EMPTY, and cheap to keep that way: the engine is pure integer arithmetic over
     * data the server hands it (P2 Decision B), so it has no runtime dependency to declare and a
     * first entry here is a decision somebody should have to write down. The same policy as the two
     * client-side scans below, one layer out — those forbid the code, this forbids buying it.
     *
     * @var list<string>
     */
    private const PACKAGE_RUNTIME_DEPENDENCY_ALLOW_LIST = [];

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

    /**
     * The same two client-side scans, over `packages/` — the repository's TypeScript (P2 Task 6).
     *
     * WHY THIS EXISTS, MEASURED RATHER THAN ARGUED. Both scans below take their scope from
     * `resource_path('js')`, so `packages/` escaped them by construction — a loophole, not a
     * permission. It was proved a loophole rather than assumed: `packages/engine/src/scratch.ts`
     * was planted containing a `new Date()` call and a two-entry array of quoted day names, and
     * this class ran GREEN, 7 tests and 7 assertions. That green is the finding, and it is why the
     * collector below is fed into both scans rather than into a third one nobody would think to
     * extend next time.
     *
     * THE ALLOW-LIST STAYS EMPTY IN BOTH DIRECTIONS, and that is affordable rather than aspirational:
     * P2 Decision B leaves the engine with no `Date`, no instant and no timezone at all — its date
     * type is a branded `Y-m-d` string over integer civil-date arithmetic and its time type is
     * minutes from local midnight — so there is nothing here to exempt. All ten needles and the
     * weekday pattern measured ZERO hits across `packages/` at Tasks 2, 4 and 5, including against
     * the calendar mirror itself, which is the one file in this repository that would have had an
     * excuse. The absence is real rather than allow-listed, which is `ClinicHooksTest`'s property
     * and the only kind worth having.
     *
     * The extension filter is deliberate and narrower than the `resources/js` scan's (which has
     * none): a package directory holds `package.json`, lock files and, once anybody runs `npm
     * install` inside one, `node_modules` — and a needle found in a third party's shipped source is
     * noise that would force the first allow-list entry within a week. `node_modules` and `dist` are
     * skipped for the same reason: they are somebody else's code and our own build output.
     *
     * STATED RESIDUAL: a source scan of our own files cannot see a date library arriving as an npm
     * DEPENDENCY. `test_no_package_under_packages_declares_a_runtime_dependency` closes the direct
     * half of that; a date library arriving transitively, under a devDependency, is not covered and
     * is not cheaply coverable.
     *
     * @return list<\SplFileInfo>
     */
    private function scannedFilesUnderPackages(): array
    {
        $dir = base_path('packages');

        if (! File::exists($dir)) {
            return [];
        }

        $files = [];

        foreach (File::allFiles($dir) as $file) {
            $relative = $this->relativePath($file);

            if (str_contains($relative, '/node_modules/') || str_contains($relative, '/dist/')) {
                continue;
            }

            if (! in_array(strtolower($file->getExtension()), ['ts', 'mts', 'js', 'mjs'], true)) {
                continue;
            }

            $files[] = $file;
        }

        return $files;
    }

    /**
     * A guard iterating an empty set is green for the wrong reason, and a moved or renamed
     * directory is exactly how one gets there — the same non-vacuity floor `ClinicHooksTest` puts
     * under its two globs. Named files rather than a bare count alone, because a count survives the
     * engine's sources being replaced by something else entirely.
     *
     * @param list<string> $relatives
     */
    private function assertThePackagesScanFoundTheEngine(array $relatives): void
    {
        foreach (['packages/engine/src/index.ts', 'packages/engine/src/calendar/index.ts'] as $expected) {
            $this->assertContains(
                $expected,
                $relatives,
                "The packages/ scan did not find {$expected}. Either the engine moved (point this "
                .'collector at it) or this scan is guarding nothing at all — which looks identical '
                .'to a clean tree.'
            );
        }

        $this->assertGreaterThanOrEqual(
            8,
            count($relatives),
            'The packages/ scan found fewer files than the engine has. A collector that quietly '
            .'stopped matching most of the tree is a guard that quietly stopped guarding.'
        );
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
     *
     * SCOPE WIDENED 2026-08-20 (P2 Task 6) to `packages/` — see `scannedFilesUnderPackages()` for
     * the plant that proved the old scope was a loophole, and for why the allow-list is still
     * empty. `resources/js` gets a prop from the controller; the engine holds no instant at all.
     * Two roots, one offender set, one assertion.
     */
    public function test_no_client_side_date_construction_appears_under_resources_js_and_packages(): void
    {
        $offenders = [];
        $packageFiles = $this->scannedFilesUnderPackages();

        foreach (array_merge($this->jsFilesUnderResources(), $packageFiles) as $file) {
            $relative = $this->relativePath($file);
            $contents = (string) file_get_contents($file->getPathname());

            foreach (self::JS_DATE_NEEDLES as $needle) {
                if (str_contains($contents, $needle)) {
                    $offenders[] = "{$relative} contains \"{$needle}\"";
                }
            }
        }

        $this->assertThePackagesScanFoundTheEngine(array_map(
            fn (\SplFileInfo $file): string => $this->relativePath($file),
            $packageFiles
        ));

        $this->assertSame(
            [],
            $offenders,
            "Decision A: resources/js performs no date arithmetic — the server sends formatted ".
            "labels and enumerated ranges instead. Decision B: packages/ holds no Date object, no ".
            "instant and no timezone, so it needs no exemption either. Found:\n".implode("\n", $offenders)
        );
    }

    /**
     * THE OTHER HALF OF DECISION A, AND THE HALF THE NEEDLE SET ABOVE CANNOT SEE. Those ten needles
     * all match date CONSTRUCTION, so a component that writes the seven day names down and orders
     * them itself passes every one of them while being exactly the second answer to "what order does
     * this department's week run in" that AR-08 forbids. `Calendar::weekdayColumns()` sends that
     * array already labelled and already rotated to `weekStartIsoDay()`; a screen consumes it.
     *
     * Task 1 flagged the gap and left the decision to the task where the offence first becomes
     * possible — P1e Task 4's clinic form and Task 5's clinic map, both of which have a weekday
     * `<select>` or seven column headers. This is that decision, and it was taken on MEASUREMENT
     * rather than taste (ruling 42): the pattern below matched ZERO files across the whole of
     * `resources/js` before the clinics screen was written, so it costs no allow-list entry and
     * blinds no file. The bare substrings were rejected in the same measurement — `Mon` matches
     * `Month`, which the holidays screen legitimately says twice.
     *
     * ALLOW-LIST DELIBERATELY ABSENT, exactly like the check above and for its reason: a screen that
     * wants to name a day asks the server for the name. It is also why this needle is here rather
     * than only in `tests/js/Clinics.test.js` — a per-file assertion protects the one file somebody
     * remembered to write it in, and the next clinic surface would be unguarded by default.
     *
     * The pattern is a QUOTED WHOLE WORD in any of the three JavaScript string delimiters, which is
     * how such a list is actually spelled. Prose in a comment is matched too, deliberately: a
     * docblock is scanned source everywhere else in this suite, and a file explaining the array it
     * is about to build is not a file this guard should let past.
     *
     * SCOPE WIDENED 2026-08-20 (P2 Task 6) to `packages/`, and this half is the one that actually
     * binds there. The engine's calendar mirror implements `weekdayColumns()` — the department's
     * week in the order it runs — and the obvious way to write it is with the seven names in an
     * array, which is what `golden.json`'s `weekday_columns` block carries. It does not: the mirror
     * returns ISO numbers and weekend flags, the names stay in `lang/en/calendar.php` (AR-07), and
     * this scan is what keeps that true after the next author who has not read owner decision X.
     */
    public function test_no_hardcoded_weekday_vocabulary_appears_under_resources_js_and_packages(): void
    {
        $pattern = '/([\'"`])(Mon(day)?|Tue(sday)?|Wed(nesday)?|Thu(rsday)?|Fri(day)?|Sat(urday)?|Sun(day)?)\1/';

        $offenders = [];
        $packageFiles = $this->scannedFilesUnderPackages();

        foreach (array_merge($this->jsFilesUnderResources(), $packageFiles) as $file) {
            $relative = $this->relativePath($file);
            $contents = (string) file_get_contents($file->getPathname());

            if (preg_match_all($pattern, $contents, $matches) > 0) {
                $offenders[] = "{$relative} names ".implode(', ', array_unique($matches[0]));
            }
        }

        $this->assertThePackagesScanFoundTheEngine(array_map(
            fn (\SplFileInfo $file): string => $this->relativePath($file),
            $packageFiles
        ));

        $this->assertSame(
            [],
            $offenders,
            "Decision A: the day names and the order the week runs in are Calendar::weekdayColumns()'s, ".
            "and reach the client as a prop — in packages/ they arrive in the evaluation context ".
            "(owner decision X) rather than being written down. Found:\n".implode("\n", $offenders)
        );
    }

    /**
     * The half a source scan of our own files CANNOT see: a date library arriving as a dependency.
     *
     * Bought here rather than left as a residual because it is one comparison over a short list —
     * measured, per ruling 42, not assumed. `packages/engine/package.json` declares no
     * `dependencies` at all today, so the allow-list below is EMPTY and costs nothing; the check is
     * the difference between "the engine does its own integer arithmetic" being a property of this
     * tree and being a property of the day somebody last read it.
     *
     * The needle is the DECLARATION, not a name list. An allow-list of forbidden library names
     * (`dayjs`, `date-fns`, `luxon`, `moment`, …) would be a list of the ones somebody thought of,
     * and the next one is by definition not on it. `peerDependencies` and `optionalDependencies`
     * are read for the same reason: all three ship to a consumer, and only `devDependencies` does
     * not.
     *
     * STATED RESIDUAL, uncovered and not cheaply coverable: a date library arriving TRANSITIVELY,
     * as a dependency of a devDependency, is invisible to this check. It would not reach the
     * browser bundle through this package's own imports, which is what makes it a residual rather
     * than a hole — but it is not zero, and reading a lock file to close it would be a second
     * definition of what the package manager already resolves.
     */
    public function test_no_package_under_packages_declares_a_runtime_dependency(): void
    {
        $offenders = [];

        foreach ($this->packageManifests() as $relative => $manifest) {
            foreach (['dependencies', 'peerDependencies', 'optionalDependencies'] as $section) {
                foreach (array_keys((array) ($manifest[$section] ?? [])) as $name) {
                    if (in_array($name, self::PACKAGE_RUNTIME_DEPENDENCY_ALLOW_LIST, true)) {
                        continue;
                    }

                    $offenders[] = "{$relative} declares {$section}: {$name}";
                }
            }
        }

        $this->assertSame(
            [],
            $offenders,
            'Decision B: the engine computes dates with integer civil-date arithmetic and needs no '
            .'library to do it, so a runtime dependency here is either a date library the source '
            ."scans cannot see or a browser-bundle cost nobody priced. Found:\n".implode("\n", $offenders)
        );
    }

    /**
     * The twin, and it is deliberately NOT the staleness twin the other allow-lists here carry.
     *
     * Measured: with an allow-list that is empty by design, a staleness check iterates zero entries
     * and passes — on a healthy tree, on a deleted `packages/` directory, and on a manifest that
     * has been renamed out of the scan's reach alike. It would be a test that cannot fail, which is
     * the shape this suite exists to refuse. The failure the dependency check can actually have is
     * finding nothing to check, so that is what this asserts.
     */
    public function test_the_package_dependency_scan_reads_the_manifests_it_claims_to(): void
    {
        $manifests = $this->packageManifests();

        $this->assertArrayHasKey(
            'packages/engine/package.json',
            $manifests,
            'The dependency scan found no manifest for the engine. Either the package moved or the '
            .'scan is asserting over an empty set, and those two look identical from a green suite.'
        );

        $this->assertSame('@endorsement/engine', $manifests['packages/engine/package.json']['name'] ?? null);
    }

    /**
     * Every `package.json` under `packages/`, decoded, keyed by repository-relative path.
     *
     * `node_modules` is skipped: a workspace install puts thousands of third-party manifests under
     * it, and every one of them declares dependencies.
     *
     * @return array<string, array<string, mixed>>
     */
    private function packageManifests(): array
    {
        $dir = base_path('packages');

        if (! File::exists($dir)) {
            return [];
        }

        $manifests = [];

        foreach (File::allFiles($dir) as $file) {
            $relative = $this->relativePath($file);

            if ($file->getFilename() !== 'package.json' || str_contains($relative, '/node_modules/')) {
                continue;
            }

            $decoded = json_decode((string) file_get_contents($file->getPathname()), true);

            $this->assertIsArray($decoded, "{$relative} is not valid JSON — the dependency scan cannot read it.");

            $manifests[$relative] = $decoded;
        }

        return $manifests;
    }
}
