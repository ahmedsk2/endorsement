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
 * over app/ + database/ + routes/, deliberately coarse rather than a static analyser.
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
}
