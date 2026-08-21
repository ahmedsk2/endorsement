<?php

namespace Tests\Feature\Build;

use Illuminate\Support\Facades\File;
use Tests\Support\SourceScanner;
use Tests\TestCase;

/**
 * P2 Decision I, third half: `App\Support\Engine\EvaluationRequest` is the ONE assembler.
 *
 * ## THE DEFECT THIS GUARD KEEPS CLOSED, AND WHY THE FIX ALONE DID NOT CLOSE IT
 *
 * P2 Task 21 finding 6: `ContextBuilder::forHorizon()` builds the day vector AND the availability
 * denominator over the single range it is handed, while the CG-10 horizon carries FOUR dates —
 * `[from, to]` is the period being drafted and `[evaluableFrom, evaluableTo]` is how far the
 * read-only carry-in tail reaches. A caller that loads the context over the PERIOD and then widens
 * the horizon to reach the tail has told the engine two incompatible things, and **absence of data
 * and unavailability are indistinguishable in a list of dates**: `call_frequency_max` reads the
 * widened window as *"available on one of these days"*, permits `floor(1 / 3) = 0` calls, and fires
 * loudest on a month that breaches nothing.
 *
 * Task 24 closed it STRUCTURALLY inside `EvaluationRequest` — both evaluable bounds are read off the
 * day vector that was actually built, so no expression in that file can hand the horizon a date the
 * context does not describe. That is a real fix and it is a fix to ONE FILE. Nothing stopped a
 * second caller loading the context itself and minting its own horizon beside it, which is the
 * defect verbatim, one file along, with `EvaluationRequestTest` still green — and a second caller
 * is not hypothetical: P3's workbench and P4's solver each want a context.
 *
 * `EvaluationRequestTest`'s own docblock argues the structural fix at length and asserts it only
 * about the file that has it. This guard is the half that generalises: the fix is worth having
 * because it is the only path, and "the only path" is a property of the whole application, not of
 * the file at the end of it.
 *
 * ## THE TWO NEEDLES, AND WHY BOTH
 *
 * They fail differently, and either alone stays green on half the defect.
 *
 *  - **`forHorizon(`** — a second CALLER of the loader. This is the shape that reintroduces the
 *    widening defect by building a context nobody widened.
 *  - **`'evaluableFrom' =>`** — a second MINT of a horizon. A caller that assembled the horizon
 *    itself and reused `EvaluationRequest`'s context would not name `forHorizon(` at all, and it is
 *    the horizon half that carries the wrong number.
 *
 * The mint needle is quoted-key-with-arrow on purpose. `EngineEvaluate::summarise()` READS
 * `$horizon['evaluableFrom']` to print it, which is a read and not a mint, and a bare-word needle
 * would have failed the build on the command whose whole job is to display the fix. Both spellings
 * of the quote are matched — every sibling guard in this suite carries both since ruling 66's sweep
 * found a whole miss that was one character wide.
 *
 * ## SCOPE, AND WHAT IS DELIBERATELY NOT SCANNED
 *
 * `app/` entire, comments stripped. Not `routes/` or `database/` — neither can hold a caller that
 * matters, and both measured zero, so scanning them would buy nothing but a wider surface for a
 * false positive. Comments are stripped for this phase's most-repeated reason: `EvaluationRequest`'s
 * own docblock explains the defect in the vocabulary of the defect, twice, and a raw scan would fail
 * the build on the explanation and teach the next author to delete it.
 *
 * **STATED RESIDUAL:** a second assembler that called `ContextBuilder::forHorizon` through a
 * variable class name, or spelled the horizon key by concatenation, is invisible here — the residual
 * every needle scan in this suite carries and none has ever closed. What is NOT a residual is a
 * second assembler written the way the first one is, which is the only way anybody is going to write
 * one.
 */
class EngineHasOneAssemblerTest extends TestCase
{
    /**
     * Needle => the files allowed to name it, with why. NEVER a whole-file exemption and never a
     * bare count: a count survives one file being replaced by another, which is exactly the change
     * this guard exists to notice.
     *
     * @var array<string, list<string>>
     */
    private const EXPECTED = [
        // The loader's own declaration, and its one caller.
        'forHorizon(' => [
            'app/Support/Engine/ContextBuilder.php',
            'app/Support/Engine/EvaluationRequest.php',
        ],
        // The horizon is minted in exactly one expression in this application.
        'evaluableFrom' => [
            'app/Support/Engine/EvaluationRequest.php',
        ],
    ];

    /**
     * `evaluableFrom` is matched as a MINT (a quoted key followed by `=>`) and not as a word, so
     * that reading the value off an assembled horizon stays legal. `forHorizon(` is a plain
     * substring, which reaches the declaration and every call spelling alike.
     */
    private function namedIn(string $needle, string $code): bool
    {
        if ($needle === 'evaluableFrom') {
            return preg_match('/[\'"]evaluableFrom[\'"]\s*=>/', $code) === 1;
        }

        return str_contains($code, $needle);
    }

    /** @return list<string> relative paths of every PHP file under app/ */
    private function scannedFiles(): array
    {
        $out = [];

        foreach (File::allFiles(app_path()) as $file) {
            if ($file->getExtension() !== 'php') {
                continue;
            }

            $out[] = str_replace('\\', '/', str_replace(base_path().DIRECTORY_SEPARATOR, '', $file->getPathname()));
        }

        sort($out);

        return $out;
    }

    /**
     * BOTH DIRECTIONS IN ONE COMPARISON, per needle. An extra file is a second assembler; a missing
     * one means the scan has stopped reaching the code it is about, and a guard that found nothing
     * looks exactly like a clean tree.
     */
    public function test_the_loader_has_one_caller_and_the_horizon_has_one_mint(): void
    {
        $files = $this->scannedFiles();

        $this->assertContains(
            'app/Support/Engine/EvaluationRequest.php',
            $files,
            'The scan did not reach the assembler at all.'
        );

        foreach (self::EXPECTED as $needle => $expected) {
            $found = [];

            foreach ($files as $relative) {
                if ($this->namedIn($needle, SourceScanner::withoutComments(base_path($relative)))) {
                    $found[] = $relative;
                }
            }

            sort($found);
            $wanted = $expected;
            sort($wanted);

            $this->assertSame(
                $wanted,
                $found,
                "P2 Task 21 finding 6: ONE assembler builds the context and mints the horizon that\n"
                ."describes it, so the two cannot disagree about which dates the engine may answer\n"
                ."for. A second caller of the loader, or a second expression minting\n"
                ."`evaluableFrom`, reintroduces the defect `EvaluationRequest` was written to close\n"
                ."— and it does so with `EvaluationRequestTest` entirely green, because that file\n"
                ."asserts the property of the assembler and not of the application.\n"
                ."If a second entry point genuinely belongs here, it goes THROUGH\n"
                ."`EvaluationRequest::forPeriod()`. Needle \"{$needle}\" was found in:\n"
                .implode("\n", $found)
            );
        }
    }

    /**
     * THE VACUITY TWIN. Both assertions above are satisfied by a matcher that matches nothing at
     * all in a tree where the expected files have been renamed away, and by a `evaluableFrom`
     * pattern miswritten so it can never fire. So each needle is required to see the thing it is
     * about, on the file that definitely has it.
     */
    public function test_the_needles_can_actually_see_the_assembler(): void
    {
        $code = SourceScanner::withoutComments(base_path('app/Support/Engine/EvaluationRequest.php'));

        $this->assertTrue($this->namedIn('forHorizon(', $code), 'the loader-call needle cannot see the one call');
        $this->assertTrue($this->namedIn('evaluableFrom', $code), 'the horizon-mint pattern cannot see the one mint');

        // And the mint pattern must NOT fire on a read, or it would have failed the build on the
        // command that prints the value — the reason it is a pattern rather than a word.
        $this->assertFalse(
            $this->namedIn('evaluableFrom', '$this->line($horizon[\'evaluableFrom\']);'),
            'the mint pattern fires on a READ of the horizon, which makes it a needle nobody can '
            .'display the fix past'
        );
    }

    /**
     * The stripper, pinned in BOTH directions on the file this guard is about. Leaving prose behind
     * is a noisy false positive here — `EvaluationRequest`'s docblock names `forHorizon()` twice —
     * and eating code is the silent one, in which both needles miss at once and the run is
     * indistinguishable from a clean tree.
     */
    public function test_the_scan_strips_comments_and_still_sees_the_code(): void
    {
        $path = base_path('app/Support/Engine/EvaluationRequest.php');
        $raw = (string) file_get_contents($path);
        $code = SourceScanner::withoutComments($path);

        $this->assertStringContainsString(
            'THIS FILE DECIDES NOTHING A CONDITION WOULD DECIDE',
            $raw,
            'EvaluationRequest no longer carries the prose this calibration is pinned on. Point it '
            .'at a sentence it does carry.'
        );

        $this->assertStringNotContainsString(
            'THIS FILE DECIDES NOTHING A CONDITION WOULD DECIDE',
            $code,
            'The stripper left docblock prose behind.'
        );

        $this->assertStringContainsString(
            'final class EvaluationRequest',
            $code,
            'The stripper ate code, not just prose. Both needles above would then miss at once.'
        );
    }
}
