<?php

namespace Tests\Feature\Build;

use Tests\Support\SourceScanner;
use Tests\TestCase;

/**
 * P2 Decision I, first half: `App\Support\Engine` is a READER. Nothing in it writes a row.
 *
 * ## WHY A GUARD RATHER THAN A CONVENTION
 *
 * P2 ships no migration and writes no table at all, so every existing single-writer guard must stay
 * green with no new allow-list entry — and the file most likely to break that is this one, because
 * the convenience a context builder attracts is a CACHE. "Just store the assembled context so the
 * workbench does not rebuild it per keystroke" is the change that arrives in P3, it is entirely
 * reasonable-sounding, and it turns a reader into a writer of a table no single-writer guard yet
 * covers. Stating it in a docblock is what this codebase has already measured to be insufficient
 * eleven times; a guard is what a plan trips over.
 *
 * The scan is a GLOB over `app/Support/Engine/*.php`, so a class added to that namespace joins it
 * unasked — `RotaAccessTest`'s and `ClinicHooksTest`'s shape, for their recorded reason.
 *
 * ## THE NEEDLES
 *
 * Every one of ruling 42's three writer shapes, ruling 50's two, and ruling 66's sixth, plus the
 * destructive verbs — VERB-ONLY rather than model-qualified, which is affordable here in a way it
 * is not in a single-writer guard. Those guards must name a model because they scan the whole
 * application and `create(` alone would match hundreds of legitimate files. This one scans four
 * hundred lines in one namespace whose entire job is to read, so the widest possible needle costs
 * nothing and catches a writer of a table nobody has invented yet — including one written against
 * the query builder, a relation, or a model this guard has never heard of.
 *
 * MEASURED before being bought (ruling 42): every needle below matches ZERO files in the scanned
 * namespace today, so the allow-list is empty and nothing had to be excused into it.
 *
 * ## THE TWO KNOWN BLIND SPOTS ARE BOTH NEEDLED
 *
 * `Model::query()->create(` is the sixth writer shape (ruling 66, found by planting exactly that
 * file against a guard that stayed green), and `$model->update([` is this codebase's house idiom
 * (ruling 50, measured green against a plant rewriting six columns across two guarded tables). The
 * bare `create(` and `update(` needles here reach both, which is the dividend of not having to
 * qualify by model — recorded explicitly because "the bare needle happens to cover it" is exactly
 * the reasoning that left five sibling guards blind.
 *
 * ## COMMENTS ARE STRIPPED, AND THE REASON IS THE PHASE'S MOST-REPEATED FINDING
 *
 * This is an ABSENCE guard whose vocabulary is the vocabulary of the rule it enforces:
 * `ContextBuilder`'s docblock has to be able to say that it stores nothing and creates nothing, and
 * a raw scan would fail the build on the sentence explaining why the file is safe. That teaches
 * people to delete the explanation, which is `RotaAccessTest`'s recorded departure and
 * `SourceScanner`'s whole reason for existing. Nine times in this phase a docblock has been scanned
 * source; this is the tenth file to have to answer for it, and it answers the same way — strip, and
 * pin the stripper in BOTH directions, because a stripper that ate code would disable every needle
 * at once and look exactly like a clean tree.
 *
 * ## WHAT THIS GUARD DOES NOT COVER, STATED RATHER THAN IMPLIED
 *
 *  - `$model->{$verb}()` where the verb is a variable, and any concatenated spelling. No substring
 *    scan reaches a name that is not written down; `DemoDepartment::remove()` is the tree's own
 *    example of a real writer no needle can see.
 *  - A write performed by something this namespace CALLS. Nothing here calls a writer today, and
 *    the sibling single-writer guards are what would catch one that appeared — a guard that passes
 *    while the reader of table A writes table B through a helper has stopped meaning anything.
 *  - The four app-wide raw needles `off_roster` / `offRoster` / `callEligib` / `call_eligib`
 *    (P2 invariant 4) are NOT re-checked here. `RotaAccessTest::test_nothing_in_the_rota_infers_
 *    on_call_eligibility` already walks `File::allFiles(app_path())` with raw
 *    `file_get_contents` — comments included — with no allow-list at all, so this namespace is
 *    inside its reach by construction and P2 adds no entry to it. A second copy of those four
 *    literals here would be a second definition that could drift from the one that matters.
 */
class EngineIsAReaderTest extends TestCase
{
    private const ENGINE_GLOB = 'app/Support/Engine/*.php';

    /**
     * A file allowed to write, and the needles it is allowed to write with. NEVER a whole-file
     * exemption — the shape `RulesLiveOnlyInTheEngineTest` records as strictly better, because a
     * blanket entry blinds the guard to every OTHER shape appearing in that same file later.
     *
     * DELIBERATELY EMPTY, and there is no staleness twin over it for the reason P2 Task 6 measured
     * empirically: a staleness check over an allow-list that is empty by design passes on a healthy
     * tree, on a deleted directory and on a renamed module alike, which is a test that cannot fail.
     * The honest twins for an empty list are the two below — the scan reads a real set of files,
     * and the needles are capable of matching a real writer.
     *
     * @var array<string, list<string>>
     */
    private const ALLOW_LIST = [];

    /**
     * @var list<string>
     */
    private const WRITER_NEEDLES = [
        'create(',
        'insert(',
        'update(',
        'save(',
        'delete(',
        'upsert(',
        'firstOrCreate(',
        'updateOrCreate(',
        'truncate(',
        'destroy(',
        'forceFill(',
        'fill(',
        'increment(',
        'decrement(',
        'sync(',
        'attach(',
        'detach(',
        'DB::table(',
        'DB::statement(',
        'DB::insert(',
        // The two known blind spots, spelled out rather than left to the bare verbs above. Ruling
        // 66's sixth writer shape and ruling 50's house idiom.
        'query()->create(',
        '->update([',
    ];

    /** @return list<string> absolute paths */
    private function engineFiles(): array
    {
        return glob(base_path(self::ENGINE_GLOB)) ?: [];
    }

    private function relative(string $path): string
    {
        return str_replace('\\', '/', str_replace(base_path().DIRECTORY_SEPARATOR, '', $path));
    }

    /**
     * Asserted over the whole SET, never inside a foreach that stops guarding once the last
     * offender is fixed (`CompiledCssIsLightOnlyTest` writes that failure mode up).
     */
    public function test_nothing_in_the_engine_namespace_writes_a_row(): void
    {
        $offenders = [];

        foreach ($this->engineFiles() as $path) {
            $relative = $this->relative($path);
            $exempt = self::ALLOW_LIST[$relative] ?? [];
            $code = SourceScanner::withoutComments($path);

            foreach (self::WRITER_NEEDLES as $needle) {
                if (in_array($needle, $exempt, true)) {
                    continue;
                }

                if (str_contains($code, $needle)) {
                    $offenders[] = "{$relative} names \"{$needle}\"";
                }
            }
        }

        $this->assertSame(
            [],
            $offenders,
            "P2 Decision I: App\\Support\\Engine builds the evaluation context and WRITES NOTHING.\n"
            ."P2 ships no migration and no table of its own, so every single-writer guard in this\n"
            ."suite must stay green with no new allow-list entry — and the convenience that breaks\n"
            ."that is a cache of the assembled context, which is a reasonable-sounding change that\n"
            ."turns this reader into the one writer nothing yet guards. If a write genuinely belongs\n"
            ."here, it needs a table, a single-writer guard of its own, and an entry below naming\n"
            ."the file AND the one needle with the reason. Found:\n".implode("\n", $offenders)
        );
    }

    /**
     * A guard iterating an empty set is green for the wrong reason, and a renamed directory is
     * exactly how one gets there. Named files rather than a bare count, because a count survives
     * the namespace being replaced by something else entirely.
     */
    public function test_the_scan_reads_a_real_set_of_files(): void
    {
        $relatives = array_map(fn (string $path): string => $this->relative($path), $this->engineFiles());

        $this->assertContains(
            'app/Support/Engine/ContextBuilder.php',
            $relatives,
            'The glob did not find the context builder. Either it moved or this guard is scanning '
            .'nothing at all, and those two look identical from a green suite.'
        );
    }

    /**
     * THE VACUITY TWIN (ruling 66). The scan above is satisfied by a needle list spelled for a
     * shape nothing in this codebase uses, and such a list is green against every real writer too.
     * So a KNOWN writer must match — `ClinicWriter` is the tree's own control, the single writer of
     * two tables, and it is scanned here for no other purpose.
     */
    public function test_the_needles_can_actually_see_a_writer(): void
    {
        $code = SourceScanner::withoutComments(base_path('app/Support/Clinics/ClinicWriter.php'));

        $matched = array_values(array_filter(
            self::WRITER_NEEDLES,
            static fn (string $needle): bool => str_contains($code, $needle),
        ));

        $this->assertNotSame(
            [],
            $matched,
            'ClinicWriter — a file whose whole purpose is to write two tables — matches none of '
            .'these needles, so this guard is watching for a shape nothing in the tree uses and '
            .'would stay green against a writer spelled the way the real ones are.'
        );
    }

    /**
     * The stripper, pinned in BOTH directions against a real file, per `SourceScanner`'s recorded
     * discipline. Leaving prose behind is a noisy false POSITIVE; eating code is a silent false
     * NEGATIVE in which every needle above misses at once and the run looks exactly like a clean
     * tree.
     */
    public function test_the_scan_strips_comments_and_still_sees_the_code(): void
    {
        $path = base_path('app/Support/Engine/ContextBuilder.php');
        $raw = (string) file_get_contents($path);
        $code = SourceScanner::withoutComments($path);

        $this->assertStringContainsString(
            'it is THE SERIALISER',
            $raw,
            'ContextBuilder no longer carries the prose this calibration is pinned on. Point it at '
            .'a sentence it does carry.'
        );

        $this->assertStringNotContainsString(
            'it is THE SERIALISER',
            $code,
            'The stripper left docblock prose behind.'
        );

        $this->assertStringContainsString(
            'final class ContextBuilder',
            $code,
            'The stripper ate code, not just prose. Every needle in this guard would then miss at '
            .'once, and the run would look exactly like a clean tree.'
        );
    }
}
