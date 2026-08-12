<?php

namespace Tests\Feature\Build;

use Illuminate\Support\Facades\File;
use Tests\TestCase;

/**
 * Review minor 12. `App\Support\Csv` is the one CSV WRITER (Decision F — formula-injection
 * neutralisation belongs in exactly one place, `CsvInjectionTest`), and
 * `App\Support\Roster\CsvRosterReader` is the one CSV READER (Decision E/F — the unNeutralise
 * pairing and the UTF-8 refusal both belong in exactly one place too). A second writer would skip
 * neutralisation; a second reader would skip the un-neutralise pairing, the UTF-8 check, or the
 * `escape: ''` fix (review minor 11) — three ways to reintroduce a bug this file already fixed
 * once, silently, in a class nobody thought to check.
 *
 * Same species as `PersonLevelsHaveOneWriterTest`, `RosterNeverMintsCredentialsTest`,
 * `ContactFieldsAreProjectedOnceTest` and `PersonActiveHasOneWriterTest`: a plain substring scan
 * over app/ + database/ + routes/, deliberately coarse rather than a static analyser. `tests/` is
 * NOT scanned; `database/` IS, factories and seeders included — the verdict every sibling now
 * states in the same words (made uniform by the 2026-08-12 sweep, ruling 66).
 *
 * ---------------------------------------------------------------------------------------
 * THE 2026-08-12 SWEEP (ruling 66) ADDED NOTHING HERE. This guard needles PRIMITIVE NAMES rather
 * than writer shapes, so the seventeen-shape writer probe does not apply to it; NINE CSV routes
 * were probed instead, and the five needles named all SIX that touch a PHP CSV primitive —
 * `fputcsv($h, …)` and `$file->fputcsv(…)` both, `fgetcsv`, `str_getcsv`, `setCsvControl` and
 * `SplFileObject::READ_CSV`. The other three are the residuals below.
 *
 * RESIDUALS, stated rather than implied, and both are the same shape from two directions:
 *   - `implode(',', $row)` on write and `explode(',', $line)` on read. A hand-rolled CSV never
 *     touches a primitive this guard can name, and it is precisely the path that would skip
 *     `Csv::neutralise()` on write or the un-neutralise pairing on read. Nothing here reaches it;
 *     `CsvInjectionTest` asserting the WRITE and the READ as a PAIR is what would catch the
 *     resulting round-trip damage.
 *   - A third-party CSV library (`league/csv`'s `Writer::createFromPath`, for instance). Not in
 *     `composer.json` today; adding one would need this needle list extended with its entry
 *     points, which is a decision rather than an oversight.
 */
class CsvIsTheOnlyReaderWriterTest extends TestCase
{
    /** Every file allowed to touch the CSV primitives, with why. */
    private const ALLOW_LIST = [
        // The one writer.
        'app/Support/Csv.php',
        // The one reader.
        'app/Support/Roster/CsvRosterReader.php',
    ];

    private const NEEDLES = [
        'fputcsv(',
        'fgetcsv(',
        'str_getcsv(',
        'setCsvControl(',
        'READ_CSV',
    ];

    public function test_only_csv_and_csvrosterreader_touch_the_csv_primitives(): void
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
            "CSV must be written only by App\\Support\\Csv and read only by ".
            "App\\Support\\Roster\\CsvRosterReader (review minor 12).\n".implode("\n", $offenders));
    }

    /** A stale allow-list is a silently disabled guard. */
    public function test_every_allow_listed_file_still_exists(): void
    {
        foreach (self::ALLOW_LIST as $relative) {
            $this->assertFileExists(base_path($relative), "Allow-listed file {$relative} is gone — prune the list.");
        }
    }

    /**
     * THE VACUITY TWIN (2026-08-12 sweep, ruling 66). The scan above is satisfied by a tree in
     * which nothing touches a CSV primitive at all. Checked PER FILE rather than over the pair,
     * because this guard names TWO controls that do OPPOSITE things — a needle list healthy for
     * the writer and blind for the reader would pass a pooled check and be exactly half a guard.
     */
    public function test_the_writer_and_the_reader_really_do_touch_the_primitives(): void
    {
        foreach (['app/Support/Csv.php', 'app/Support/Roster/CsvRosterReader.php'] as $control) {
            $source = (string) File::get(base_path($control));

            $matched = array_values(array_filter(
                self::NEEDLES,
                static fn (string $needle): bool => str_contains($source, $needle),
            ));

            $this->assertNotSame([], $matched,
                $control.' matches none of this guard\'s needles, so the guard is scanning for a '
                .'shape nothing in the tree uses — it would stay green against a second reader or '
                .'writer spelled the way the real one is.');
        }
    }
}
