<?php

namespace Tests\Feature\Build;

use Tests\TestCase;

/**
 * Tailwind scans TEMPLATES, and templates live in exactly two directories.
 *
 * ## The defect, which was shipping before this guard existed
 *
 * Tailwind v4 detects its own sources: every tracked, non-gitignored file in the repository is
 * scanned for anything that spells a utility class. It does not care whether the file is a Vue
 * page, a TypeScript module, a PHP test, a supervisord config or a Markdown design document — and
 * ordinary English contains utility names. `container`, `static`, `shrink`, `blur`, `outline`,
 * `uppercase` and `shadow` are all words.
 *
 * The shipped bundle proved it. Measured at P2 Task 23 by diffing the built CSS with detection on
 * and off, the production stylesheet was carrying rules extracted from:
 *
 *  - **`docker/supervisord.conf` and `docker-compose.production.yml`** — `.[program:nginx]`,
 *    `.[program:php-fpm]`, `.[program:scheduler]`, `.[no-new-privileges:true]`. Four rules whose
 *    declarations are not CSS at all, generated from a process supervisor's section headers.
 *  - **`docs/superpowers/specs/2026-07-26-login-redesign-design.md`** — `.min-h-[34vh]`,
 *    `.rounded-2xl`, `.bg-blue-600` and the rest of a raw palette this project's own design rules
 *    forbid in markup. A superseded draft in a design document was compiling CSS. The layout that
 *    actually ships uses `34dvh`, so the emitted rule was not even the one on screen.
 *  - **`packages/engine`** — `.block-3` and `.block-13`, academic-year period keys that happen to
 *    spell a block-size utility.
 *
 * Nothing dropped by the narrowing traces to `resources/js` or `resources/views`. That was checked
 * class by class over the whole diff, not assumed.
 *
 * ## Why `source(none)` and not an exclusion list
 *
 * An exclusion has to guess which directory the next collision is in, and the three above are in
 * three different ones. `source(none)` plus two explicit `@source` lines states the actual rule —
 * a template is a Vue page or a Blade view, and there is no third kind — and it is what lets this
 * class name the offending strings in prose without re-emitting them, since an unscanned file
 * cannot leak one.
 *
 * The cost, stated where it will be read: markup outside those two directories will silently not
 * compile its classes. A `find` for `*.blade.php` outside `resources/views` and `*.vue` outside
 * `resources/js` returns nothing today, and anything that changes that must add an `@source`
 * deliberately rather than discover this test.
 *
 * ## Both halves, because either alone rots
 *
 * The artifact assertions below go green the moment somebody renames a canary, having asserted
 * nothing — a guard that stops guarding the instant it starts succeeding. So each canary's
 * PRESENCE in its own file is asserted too, and the pair is the guard. Two canaries from two
 * different directories, deliberately: one from `packages/` and one from `docker/`, so a
 * narrowing that survived for `packages/` alone would still be caught.
 *
 * PLANTED, both directions: (1) `source(none)` removed from `resources/css/app.css` and the assets
 * rebuilt — `.block-13{block-size:…}` and `program:nginx` both returned to
 * `public/build/assets/app-*.css` and the artifact test went red naming the bundle and the string;
 * (2) the `@source "../js"` line removed and rebuilt — every real page class vanished, which the
 * source-level test catches directly. Both reverted.
 */
class TemplateScanningIsNarrowTest extends TestCase
{
    /**
     * The two canaries: a string that spells a utility, the file that owns it, and the substring
     * its rule would put in the bundle.
     *
     * Both are values another part of the tree owns rather than ones this test introduced. A
     * canary a guard planted for itself is a canary the guard's own author keeps alive.
     *
     * @var array<int, array{0: string, 1: string, 2: string}>
     */
    private const CANARIES = [
        ['block-13', 'packages/engine/src/messages.ts', '.block-13{'],
        ['[program:nginx]', 'docker/supervisord.conf', 'program:nginx'],
    ];

    private function appCss(): string
    {
        // Strip comments first: this stylesheet documents the rule in prose and the prose names
        // the directories it is about — the same reason CompiledCssIsLightOnlyTest strips them.
        return preg_replace('#/\*.*?\*/#s', '', file_get_contents(base_path('resources/css/app.css')));
    }

    /** Layer 1 — automatic detection is off. Always runs, with or without a build. */
    public function test_automatic_source_detection_is_disabled(): void
    {
        $this->assertMatchesRegularExpression(
            "/@import\s+'tailwindcss'\s+source\(none\)/",
            $this->appCss(),
            "resources/css/app.css no longer imports Tailwind with `source(none)`, so automatic ".
            'source detection is back on. It scans EVERY tracked file — docs/, docker/, tests/, '.
            'packages/ — and compiles a rule for every English word that spells a utility. The '.
            'production bundle carried rules from a supervisord config and a superseded design '.
            'document for months on exactly that route.'
        );
    }

    /** Layer 2 — and the two real template roots are declared, or nothing compiles at all. */
    public function test_the_two_template_roots_are_declared_as_sources(): void
    {
        $sources = [];
        preg_match_all('/@source\s+[\'"]([^\'"]+)[\'"]/', $this->appCss(), $sources);

        // Assert over the whole SET rather than inside a foreach: a per-item loop iterates zero
        // times once the @source lines are gone and passes having asserted nothing.
        $this->assertSame(
            ['../js', '../views'],
            $sources[1],
            'resources/css/app.css must declare exactly the two template roots — resources/js '.
            '(Vue pages) and resources/views (Blade). Under source(none) an undeclared root '.
            'compiles no classes at all, and a third entry means markup has appeared somewhere '.
            'this project says it does not live.'
        );
    }

    /** Layer 3, the vacuity twin — each canary is still in the tree to be caught. */
    public function test_every_canary_string_is_still_present_in_its_own_file(): void
    {
        foreach (self::CANARIES as [$canary, $file, $unused]) {
            $path = base_path($file);

            $this->assertFileExists(
                $path,
                "{$file} has moved. This guard needs real, non-template strings that spell ".
                'Tailwind utilities; pick another and update self::CANARIES.'
            );

            $this->assertStringContainsString(
                $canary,
                file_get_contents($path),
                "{$file} no longer contains \"{$canary}\", so the artifact assertion for it now ".
                'checks a string nothing emits and would pass with the narrowing removed. Pick '.
                'another non-template string that spells a utility and update self::CANARIES.'
            );
        }
    }

    /** Layer 4 — and no rule any canary would have produced reached the bundle. */
    public function test_built_css_bundles_carry_no_rule_from_a_non_template_file(): void
    {
        $bundles = glob(public_path('build/assets/*.css')) ?: [];

        if ($bundles === []) {
            $this->markTestSkipped('No build artifacts present — run `npm run build` first.');
        }

        foreach ($bundles as $bundle) {
            $contents = file_get_contents($bundle);

            foreach (self::CANARIES as [$canary, $file, $emitted]) {
                $this->assertStringNotContainsString(
                    $emitted,
                    $contents,
                    basename($bundle)." carries a rule built from \"{$canary}\" in {$file}. That ".
                    'is not a class name and no template uses it: it reached the stylesheet '.
                    'because Tailwind scanned a file that is not a template. Restore '.
                    "`@import 'tailwindcss' source(none)` in resources/css/app.css."
                );
            }
        }
    }
}
