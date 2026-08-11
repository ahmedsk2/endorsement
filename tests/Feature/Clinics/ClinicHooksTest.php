<?php

namespace Tests\Feature\Clinics;

use Tests\Support\SourceScanner;
use Tests\TestCase;

/**
 * CL-03 AND CL-04 ARE UNBUILT, AND THEIR ABSENCE IS ASSERTED RATHER THAN MERELY UNIMPLEMENTED.
 *
 * The MR-04 treatment (P1d owner decision 1, `RotaAccessTest`), applied to clinics. P1e ships the
 * clinic DATA those two requirements will read and records the hook only:
 *
 *  - **CL-03 — "clinics feed conditions" — is a P2 condition type.** `conditions` does not exist in
 *    this tree as a table, a class or a concept. When it arrives it reads `clinics.weekday` and
 *    `clinics.unit_id` against a date and a person's current unit; it needs no schema change here
 *    and it is not a clinic-module concern when it does.
 *  - **CL-04 — personal schedules, feeds, the on-now board, morning cover — is P3.** When it
 *    arrives it reads `ClinicRoster::forDate()`, which already answers "who does this clinic come
 *    down to on this day" and deliberately answers nothing else. It subtracts no leave, and Task 3
 *    built it so that it never queries the leave tables AT ALL — precisely so that the second scan
 *    below passes on its own merits rather than by an allow-list entry.
 *
 * WHY GUARD AN ABSENCE. "We have not built it" and "we have decided not to build it" are different
 * states, and only the second is safe to build on top of. A later plan reaching for *"the clinic
 * module already knows who is free on Tuesday"* fails the build here instead of shipping half of a
 * requirement whose other half nobody has specified.
 *
 * THE SCAN STRIPS COMMENTS, AND THAT IS THE WHOLE REASON IT CAN BE CASE-INSENSITIVE AND SHORT.
 * `ClinicRoster`'s docblock states this rule in the vocabulary the scan hunts for — "IT SUBTRACTS
 * NO LEAVE AND MUST NEVER BECOME AN ELIGIBILITY COMPUTATION", `AvailabilitySummary` named as the
 * shape it is not — because that is the paragraph a future implementer most needs to read. A
 * literal needle scan over raw source would fail the build on the rule's own statement and train
 * people to delete it, which is the argument `RotaAccessTest` already makes and this file inherits
 * along with its stripper ({@see \Tests\Support\SourceScanner}). Every other source guard in this
 * codebase matches prose ON PURPOSE and the remedy there is to spell around the shape; these two
 * are the exception, and {@see test_the_scan_strips_comments_and_still_sees_the_code} is what keeps
 * the exception honest in both directions.
 */
class ClinicHooksTest extends TestCase
{
    /**
     * CL-03's shape — a rule engine. `condition` is the load-bearing one and the rest are the
     * vocabulary design §6.3 uses for the unbuilt tables around it: a duty-hour rule that a roster
     * can violate, at some severity, blocking hard or soft, ranked against the others.
     */
    private const CONDITION_NEEDLES = [
        'post_call', 'postcall', 'condition', 'severity', 'violation',
        'hard_block', 'soft_block', 'rank_order',
    ];

    /**
     * CL-04's shape — availability arithmetic. `availab` rather than `availability` so it also
     * catches `isAvailable()`, `$available` and `AvailabilitySummary`; `subtract` because the only
     * way to compute who is free is to take leave away from the roster, and this module must never
     * be the place that happens.
     */
    private const AVAILABILITY_NEEDLES = [
        'availab', 'coverage', 'on_now', 'onnow', 'subtract',
        'personal_schedule', 'unavailable',
    ];

    public function test_nothing_in_the_clinic_module_evaluates_a_condition(): void
    {
        $this->assertSame([], $this->offenders(self::CONDITION_NEEDLES),
            "CL-03 (clinics feeding conditions) is P2. `conditions` does not exist here as a table,\n"
            ."a class or a concept, and the clinic module evaluates nothing. P1e ships the data the\n"
            ."P2 condition will read — `clinics.weekday` and `clinics.unit_id`, against a date and a\n"
            ."person's current unit — and records the hook in design §14 rather than building it.\n"
            .implode("\n", $this->offenders(self::CONDITION_NEEDLES)));
    }

    public function test_nothing_in_the_clinic_module_computes_availability(): void
    {
        $this->assertSame([], $this->offenders(self::AVAILABILITY_NEEDLES),
            "CL-04 (personal schedules, feeds, the on-now board, morning cover) is P3, and MR-04\n"
            ."(the rota driving on-call) is Stage 2. Nothing in the clinic module decides who is\n"
            ."free: `ClinicRoster` resolves who a clinic comes down to on a date and queries the\n"
            ."leave tables not at all. P1e records the hook — CL-04 reads `ClinicRoster::forDate()`\n"
            ."— and builds none of it.\n"
            .implode("\n", $this->offenders(self::AVAILABILITY_NEEDLES)));
    }

    /**
     * THE STRIPPER, PINNED IN BOTH DIRECTIONS, AGAINST REAL FILES — for `.php` and `.vue`
     * separately, because they go through completely different code paths (the tokenizer versus
     * three regexes) and a proof about one says nothing about the other.
     *
     *  - It must REMOVE prose. Both calibration files state, in their own docblocks, the very rules
     *    the two scans above hunt for. Without the stripper the guards would fail on that.
     *  - It must KEEP code. This is the half that matters: a stripper that over-reached would return
     *    comment-free AND code-free source, every needle would miss, both guards would be silently
     *    disabled — and the run would look exactly like a clean tree. Nothing else in this file can
     *    tell those two greens apart.
     *
     * Each removal assertion is paired with a positive claim about the RAW file first, so a
     * calibration that rots (somebody rewrites the docblock and the phrase is simply gone) fails
     * here loudly instead of turning this test into a tautology about an empty string.
     */
    public function test_the_scan_strips_comments_and_still_sees_the_code(): void
    {
        // --- PHP: the tokenizer path. -------------------------------------------------------
        $path = app_path('Support/Clinics/ClinicRoster.php');

        $raw = (string) file_get_contents($path);
        $code = SourceScanner::withoutComments($path);

        $this->assertStringContainsStringIgnoringCase('subtracts no leave', $raw,
            'the file this test calibrates against no longer contains the prose it was chosen for');
        $this->assertStringContainsString('AvailabilitySummary', $raw,
            'the file this test calibrates against no longer contains the prose it was chosen for');

        // Two REAL needles, one from each scan's set, proved gone from the stripped source.
        $this->assertStringNotContainsStringIgnoringCase('subtract', $code,
            'the PHP comment stripper left a docblock behind');
        $this->assertStringNotContainsStringIgnoringCase('availab', $code,
            'the PHP comment stripper left a docblock behind');

        $this->assertStringContainsString('final class ClinicRoster', $code,
            'the PHP comment stripper ate the code — every needle would miss and both guards would be vacuous');
        $this->assertStringContainsString('MasterRotaAssignment::query()', $code,
            'the PHP comment stripper ate the code — every needle would miss and both guards would be vacuous');

        // --- .vue: the regex path. ----------------------------------------------------------
        // `Map.vue` carries BOTH comment shapes the stripper handles: a `/** */` block at the top of
        // <script setup>, and `<!-- -->` comments in the template. Its docblock names CL-04's
        // vocabulary out loud, for the same reason `ClinicRoster`'s does — that screen is exactly
        // where somebody would add a "who is free" column.
        $vuePath = resource_path('js/Pages/Clinics/Map.vue');

        $vueRaw = (string) file_get_contents($vuePath);
        $vue = SourceScanner::withoutComments($vuePath);

        $this->assertStringContainsStringIgnoringCase('availability or coverage', $vueRaw,
            'the .vue file this test calibrates against no longer contains the prose it was chosen for');
        $this->assertStringContainsString('<!-- Phone:', $vueRaw,
            'the .vue file no longer carries the HTML comment shape this calibrates the second pattern on');

        $this->assertStringNotContainsStringIgnoringCase('availab', $vue,
            'the .vue block-comment stripper left a docblock behind');
        $this->assertStringNotContainsStringIgnoringCase('coverage', $vue,
            'the .vue block-comment stripper left a docblock behind');
        $this->assertStringNotContainsString('Phone:', $vue,
            'the .vue HTML-comment stripper left a template comment behind');

        $this->assertStringContainsString('data-testid="map-table"', $vue,
            'the .vue comment stripper ate the markup — every needle would miss and both guards would be vacuous');
        $this->assertStringContainsString('const desktopColumnCount', $vue,
            'the .vue comment stripper ate the script — every needle would miss and both guards would be vacuous');
    }

    /**
     * Every file both scans run over, collected once.
     *
     * @param  list<string>  $needles
     * @return list<string>
     */
    private function offenders(array $needles): array
    {
        $offenders = [];

        foreach ($this->clinicSurfaceFiles() as $path) {
            // Lower-cased, so the match is CASE-INSENSITIVE — only safe because the comments are
            // already gone. Over raw source it would fire on every "AVAILABILITY" in a docblock.
            // Over code it is the difference between catching `on_now` alone and also catching
            // `isAvailable()`, `$coverage` and `ConditionEngine` — the shapes an actual
            // implementation would take, none of which a fixed-case substring list would see.
            $code = mb_strtolower(SourceScanner::withoutComments($path));
            $relative = str_replace('\\', '/', str_replace(base_path().DIRECTORY_SEPARATOR, '', $path));

            foreach ($needles as $needle) {
                if (str_contains($code, mb_strtolower($needle))) {
                    $offenders[] = "{$relative}: {$needle}";
                }
            }
        }

        return $offenders;
    }

    /**
     * The clinic module, by path. `App\Support\Clinics` is a GLOB so a class added there joins both
     * scans without anybody remembering to; everything else is explicit, and every entry is asserted
     * to EXIST — a stale path in a guard's list is a guard that quietly stopped covering something,
     * and a renamed directory silently empties the glob (`RotaAccessTest` and
     * `CsvIsTheOnlyReaderWriterTest` keep the same discipline).
     *
     * The two MODELS are here although the plan's list names only the support classes, controllers,
     * form requests and screens: `Clinic` is where a `conditions()` relation or a `severity` cast
     * would land, and a guard that cannot see the model is a guard around three quarters of a
     * module.
     *
     * @return list<string>
     */
    private function clinicSurfaceFiles(): array
    {
        $files = glob(app_path('Support/Clinics/*.php')) ?: [];

        // Non-vacuity: a guard that iterates an empty set is green for the wrong reason, and a
        // renamed namespace is exactly how one gets there.
        $this->assertGreaterThanOrEqual(3, count($files), 'App\Support\Clinics is not being globbed');

        $named = [
            'app/Http/Controllers/Admin/ClinicController.php',
            'app/Http/Controllers/ClinicMapController.php',
            'app/Http/Requests/Admin/ClinicRequest.php',
            'app/Http/Requests/Admin/ClinicAttendeesRequest.php',
            'app/Models/Clinic.php',
            'app/Models/ClinicAttendee.php',
            'resources/js/Pages/Admin/Clinics.vue',
            'resources/js/Pages/Clinics/Map.vue',
        ];

        foreach ($named as $relative) {
            $path = base_path($relative);
            $this->assertFileExists($path, "The CL-03/CL-04 scan names {$relative}, which is gone — prune or correct the list.");
            $files[] = $path;
        }

        $this->assertGreaterThanOrEqual(11, count($files), 'the clinic surface scan lost files');

        return $files;
    }
}
