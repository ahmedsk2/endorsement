<?php

namespace Tests\Feature\Build;

use Illuminate\Support\Facades\File;
use Tests\Support\SourceScanner;
use Tests\TestCase;

/**
 * Munawib design §4.1: *"No PHP implementation of the rules exists anywhere."*
 *
 * ## WHY THIS EXISTS, MEASURED RATHER THAN ARGUED
 *
 * Before this class, that sentence was prose with nothing behind it. It was proved so rather than
 * assumed: `app/Support/FakeRules.php` was planted (P2 Task 11, 2026-08-20) carrying
 * `public static function minGap()`, the literals `'min_gap'`, `'severity' => 'hard'` and
 * `'eligibility'`, and a loop building a `$violations` array — a second implementation of two
 * catalog types, in the wrong language, in the wrong repository. **`php artisan test` returned
 * rc=0 with 1685 passing.** That green is the measurement, and it is why the needle set below was
 * chosen by running it over the tree rather than by writing down what felt likely.
 *
 * The engine is `packages/engine` and the CG-10 contract is its only interface. A PHP copy of a
 * rule is the `AuditChain::canonical()` defect one repository wider: two implementations of one
 * catalog, drifting silently, with the one that blocks a publish not being the one a scheduler saw
 * on the gate screen. §4.3's eventual cross-validation compares TS against the PYTHON solver; a
 * third implementation in PHP is compared against nothing at all.
 *
 * ## SCOPE, and why it is wider than the task named
 *
 * `app/`, `routes/` and `database/` — the three roots `CalendarIsTheOnlyConverterTest` scans, for
 * its recorded reason: *"I1 proved narrow scope is the recurring weakness in these source-level
 * guards: a migration or route closure is a live conversion surface too, not just app/."* P2 Task
 * 11's own text names `app/` alone. All three measured ZERO for every bought needle, so the two
 * extra roots cost nothing and close two real places a rule could be written.
 *
 * STATED RESIDUAL: `resources/js` is not scanned. A rule written in the browser would be a second
 * implementation too, but it would be a second implementation in the engine's own language, and the
 * needle set here — snake_case type keys — is the wrong shape to find one. `@engine` resolving
 * through the Vite alias is what makes the real one reachable; nothing yet makes a rival unreachable,
 * and pretending this guard covers it would be worse than saying it does not.
 *
 * ## THE NEEDLES, AND WHAT EACH ONE COST (ruling 42 — measure before adding)
 *
 * Measured over the three roots with comments stripped, case-insensitively, on 2026-08-20:
 *
 *  - **All 23 CG-07 type keys: ZERO hits.** Derived from the spec's own table, not copied — see
 *    {@link catalogTypeKeys}. Free, every one of them.
 *  - **`composition`: ZERO, and it is BOUGHT.** P2 Task 11's text predicted it would collide with
 *    *"ordinary English in docblocks about object composition"* and declined to buy it. Against this
 *    tree that prediction is wrong in two ways: the word appears in no docblock under these roots at
 *    all, and the scan strips docblocks anyway. Recorded as a correction, not carried forward.
 *  - **`eligibility`: ZERO stripped, THREE raw** — `ClinicRoster`, `AvailabilitySummary` and
 *    `RotaFill` each say *"MUST NEVER BECOME AN ELIGIBILITY COMPUTATION"* in a docblock. Bought,
 *    and it is the reason the stripper is here.
 *  - **`severity`: ZERO, and it is BOUGHT** though the task's text does not name it. A PHP rule
 *    engine grades violations, and free is free.
 *  - **`violation`: ZERO case-sensitively, ONE case-insensitively.** Bought case-insensitively at
 *    the cost of one allow-list entry, because the case-sensitive form misses `class Violation`,
 *    `$violations` and `ViolationChecker` — every form a PHP implementation would actually take, and
 *    a needle that misses the natural spelling is a needle that measures zero for the wrong reason.
 *    The entry is `RosterImport`'s `UniqueConstraintViolationException`, which is Laravel's own
 *    vocabulary in a CSV importer — not the file a scheduling rule would be born in, which is the
 *    test ruling 42 actually sets.
 *  - **`hard_block`, `soft_block`, `rank_order`: ZERO.** Bought, per the task's text.
 *  - **`condition`: TWO hits (`DemoDepartment`, `DepartmentSetup`) and NOT BOUGHT.** It collides with
 *    `config`-adjacent prose and with Laravel's own vocabulary too widely, and one of the two files
 *    is department SETUP — plausibly adjacent to where P3's gate screen lands, which is exactly the
 *    file ruling 42 says a needle must not force onto an allow-list. Stated as a residual: a PHP file
 *    that says `condition` without naming a type key, a severity or a violation goes unseen here.
 *
 * ## THE ALLOW-LIST IS PER FILE **AND PER NEEDLE**
 *
 * `RosterImport` is exempt from `violation` and from nothing else — it is still scanned for all
 * twenty-seven other needles. A whole-file exemption would have blinded the guard to a `min_gap`
 * implementation appearing in that file later, which is a strictly worse trade for the same
 * exemption.
 *
 * ## HOW THIS GUARD IS PROVED, in three directions
 *
 *  1. **It fires.** With `FakeRules.php` present it named the file and every needle in it. Deleted.
 *  2. **The stripper strips, and does not eat code.** `FakeRules.php` was re-planted with its
 *     entire body inside a docblock and this stayed GREEN;
 *     `test_the_scan_strips_comments_and_still_sees_the_code` pins both directions against a real
 *     file permanently, because a stripper that ate code would silently disable every needle at once
 *     and look exactly like a clean tree.
 *  3. **It is not scanning nothing.** `test_the_scan_reads_a_real_set_of_files` names files it must
 *     have found. A guard iterating an empty set passes on a healthy tree and on a deleted directory
 *     alike.
 *
 * The staleness twin here is a REAL one rather than the vacuous shape P2 Task 6 measured: the
 * allow-list has an entry, so the twin iterates something. Had it stayed empty by design, a
 * staleness check would have iterated zero entries and passed on everything, and the non-vacuity
 * floor would have been the only honest twin to write.
 */
class RulesLiveOnlyInTheEngineTest extends TestCase
{
    private const SPEC_FILE = 'docs/munawib/SPEC.md';

    private const CG_07_HEADER = '| Type key | Meaning | Key parameters |';

    /**
     * Needles that are not catalog type keys: the vocabulary a rule ENGINE has, whatever it calls
     * its rules. Each measured zero over the three roots before it was added.
     *
     * @var list<string>
     */
    private const ENGINE_VOCABULARY_NEEDLES = ['violation', 'severity', 'hard_block', 'soft_block', 'rank_order'];

    /**
     * File to the needles that file alone is exempt from. NEVER a whole-file exemption.
     *
     * @var array<string, list<string>>
     */
    private const ALLOW_LIST = [
        // Laravel's own UniqueConstraintViolationException, caught around a roster upsert. The
        // needle is bought case-insensitively for the `class Violation` shapes it reaches; this is
        // the one collision that costs, and a CSV importer is not where a scheduling rule is born.
        'app/Support/Roster/RosterImport.php' => ['violation'],
        // P2 Task 24's demo command, and the one entry this guard's own failure message
        // anticipates. It reads `$answer['violations']` — CG-10's array is literally named that,
        // the entrypoint returns it under that key, and there is no honest spelling of the read
        // that avoids the needle. `severity` is deliberately NOT exempt: the report groups by
        // `Condition.class` off the rows it supplied, which is the same answer by construction
        // (Decision E — `evaluate()` stamps severity FROM the row) and additionally lets the
        // report print the type key and rank a violation does not carry. So the file is still
        // scanned for `severity`, for all 23 catalog type keys and for the other three engine
        // needles — which matters, because a *"quick PHP pre-check so we do not have to spawn
        // node"* would be born in exactly this file.
        'app/Console/Commands/EngineEvaluate.php' => ['violation'],
    ];

    /** @return list<string> */
    private function needles(): array
    {
        return array_merge($this->catalogTypeKeys(), self::ENGINE_VOCABULARY_NEEDLES);
    }

    /**
     * CG-07's type keys, parsed out of the spec's own table.
     *
     * DERIVED rather than copied, and located by its HEADER rather than by line number — P2 Task 1's
     * own footnote under that table shipped citing §35 and §36 at the lines they occupied before the
     * footnote's insertion pushed them down, so a guard anchored on a number would have been wrong
     * the day it was written. `count_max / count_min` is one row carrying two keys and is split on
     * the slash.
     *
     * The consequence worth having: a twenty-fourth row appearing in the spec becomes a needle the
     * same day, with nobody having to remember this file exists.
     *
     * @return list<string>
     */
    private function catalogTypeKeys(): array
    {
        $lines = preg_split('/\r\n|\r|\n/', (string) file_get_contents(base_path(self::SPEC_FILE))) ?: [];

        $headers = array_keys(array_filter($lines, fn (string $line): bool => trim($line) === self::CG_07_HEADER));

        $this->assertCount(
            1,
            $headers,
            'Expected exactly one CG-07 catalog table header in '.self::SPEC_FILE.'. This guard locates '
            .'the table by its header row; if the header changed, point it at the new one — a parse '
            .'that silently found nothing would leave every type key unguarded.'
        );

        $start = (int) $headers[0];

        $this->assertMatchesRegularExpression(
            '/^\|[\s\-:|]+\|$/',
            trim((string) ($lines[$start + 1] ?? '')),
            'The row after the CG-07 header is not a table separator.'
        );

        $keys = [];

        for ($index = $start + 2; $index < count($lines); $index++) {
            $line = trim((string) $lines[$index]);

            if (! str_starts_with($line, '|')) {
                break;
            }

            $cells = array_map('trim', explode('|', trim($line, '|')));

            foreach (explode('/', (string) ($cells[0] ?? '')) as $key) {
                $key = trim($key);

                if ($key !== '') {
                    $keys[] = $key;
                }
            }
        }

        return $keys;
    }

    /** @return list<\SplFileInfo> */
    private function phpFilesUnderAppDatabaseAndRoutes(): array
    {
        $files = [];

        foreach ([app_path(), base_path('database'), base_path('routes')] as $dir) {
            if (File::exists($dir)) {
                $files = array_merge($files, File::allFiles($dir));
            }
        }

        return array_values(array_filter(
            $files,
            fn (\SplFileInfo $file): bool => strtolower($file->getExtension()) === 'php'
        ));
    }

    private function relativePath(\SplFileInfo $file): string
    {
        return str_replace('\\', '/', str_replace(base_path().DIRECTORY_SEPARATOR, '', $file->getPathname()));
    }

    /**
     * Assert over the whole SET, never inside a foreach that stops guarding once the last offender
     * is fixed (`CompiledCssIsLightOnlyTest` writes that failure mode up).
     */
    public function test_no_php_file_implements_a_condition_rule(): void
    {
        $needles = $this->needles();
        $offenders = [];

        foreach ($this->phpFilesUnderAppDatabaseAndRoutes() as $file) {
            $relative = $this->relativePath($file);
            $exempt = self::ALLOW_LIST[$relative] ?? [];
            $code = SourceScanner::withoutComments($file->getPathname());

            foreach ($needles as $needle) {
                if (in_array($needle, $exempt, true)) {
                    continue;
                }

                if (stripos($code, $needle) !== false) {
                    $offenders[] = "{$relative} names \"{$needle}\"";
                }
            }
        }

        $this->assertSame(
            [],
            $offenders,
            "Munawib §4.1: no PHP implementation of the conditions rules exists anywhere. The rules "
            ."live in packages/engine and reach PHP only through the CG-10 contract; a PHP copy is a "
            ."second implementation of one catalog, drifting silently, with the one that blocks a "
            ."publish not being the one a scheduler saw on the gate screen. If this is a READER "
            ."rather than a rule (App\\Support\\Engine builds the evaluation context and must name "
            ."type keys to do it), add the file AND the specific needle to ALLOW_LIST with the "
            ."reason. Found:\n".implode("\n", $offenders)
        );
    }

    /**
     * The other direction. One entry today, so this twin actually iterates something — P2 Task 6
     * measured that a staleness check over an allow-list that is empty by design passes on a healthy
     * tree, a deleted directory and a renamed module alike, which is a test that cannot fail.
     */
    public function test_every_allow_list_entry_still_needs_its_needle(): void
    {
        $stale = [];

        foreach (self::ALLOW_LIST as $relative => $exempt) {
            $path = base_path($relative);

            if (! file_exists($path)) {
                $stale[] = "{$relative} no longer exists";

                continue;
            }

            $code = SourceScanner::withoutComments($path);

            foreach ($exempt as $needle) {
                if (stripos($code, $needle) === false) {
                    $stale[] = "{$relative} no longer names \"{$needle}\"";
                }
            }
        }

        $this->assertSame(
            [],
            $stale,
            'These exemptions are no longer earning their place — remove them from ALLOW_LIST, or the '
            .'guard carries a blind spot nobody needs: '.implode(', ', $stale)
        );
    }

    /**
     * A guard iterating an empty set is green for the wrong reason, and a moved directory is exactly
     * how one gets there. Named files rather than a bare count alone, because a count survives the
     * tree being replaced by something else entirely.
     */
    public function test_the_scan_reads_a_real_set_of_files(): void
    {
        $relatives = array_map(fn (\SplFileInfo $file): string => $this->relativePath($file), $this->phpFilesUnderAppDatabaseAndRoutes());

        foreach (['app/Support/Calendar.php', 'routes/web.php'] as $expected) {
            $this->assertContains(
                $expected,
                $relatives,
                "The scan did not find {$expected}. Either the file moved or this guard is scanning "
                .'nothing at all, and those two look identical from a green suite.'
            );
        }

        $this->assertGreaterThan(
            100,
            count($relatives),
            'The scan found fewer PHP files than this application has. A collector that quietly '
            .'stopped matching most of the tree is a guard that quietly stopped guarding.'
        );
    }

    /**
     * The derivation is the other thing that can silently find nothing.
     *
     * A FLOOR rather than the catalog's exact size: the count belongs under CG-07's own table and in
     * `catalog-parity.test.ts`, which derives it, and writing it here would be a fourth place to get
     * it wrong — which this repository has already done three times.
     */
    public function test_the_needles_are_derived_from_the_catalog_table(): void
    {
        $keys = $this->catalogTypeKeys();

        foreach (['min_gap', 'count_max', 'count_min', 'eligibility', 'composition', 'forbidden_transition'] as $key) {
            $this->assertContains($key, $keys, "The CG-07 parse missed \"{$key}\".");
        }

        $this->assertGreaterThanOrEqual(
            20,
            count($keys),
            'The CG-07 parse returned fewer keys than the table has rows. A parse that half-worked '
            .'leaves most of the catalog unguarded while still passing.'
        );
    }

    /**
     * The stripper, pinned in BOTH directions against a real file — `SourceScanner`'s own recorded
     * discipline, and the reason it exists.
     *
     * `AvailabilitySummary` says *"THIS IS NOT, AND MUST NEVER BECOME, AN ON-CALL ELIGIBILITY
     * COMPUTATION"* in a docblock. That is the documentation of the very rule this guard enforces,
     * and failing the build on it would teach people to delete it — `RotaAccessTest`'s recorded
     * departure, adopted here for the same reason.
     *
     * The second assertion is the load-bearing one. Leaving a comment behind is a noisy false
     * POSITIVE; eating code is a silent false NEGATIVE in which every needle misses at once and the
     * run looks exactly like a clean tree.
     */
    public function test_the_scan_strips_comments_and_still_sees_the_code(): void
    {
        $path = base_path('app/Support/Rota/AvailabilitySummary.php');
        $raw = (string) file_get_contents($path);
        $code = SourceScanner::withoutComments($path);

        $this->assertNotFalse(
            stripos($raw, 'eligibility'),
            'AvailabilitySummary no longer says "eligibility" in prose, so this calibration proves '
            .'nothing. Point it at a file that does.'
        );

        $this->assertFalse(stripos($code, 'eligibility'), 'The stripper left docblock prose behind.');

        $this->assertStringContainsString(
            'class AvailabilitySummary',
            $code,
            'The stripper ate code, not just prose. Every needle in this guard would then miss at '
            .'once, and the run would look exactly like a clean tree.'
        );
    }
}
