<?php

namespace Tests\Feature\Build;

use Tests\TestCase;

/**
 * The compiled CSS bundle must stay LIGHT-ONLY.
 *
 * docs/DESIGN-TOKENS.md defines a single light theme: a handover screen must look identical
 * for the day and the night staff reading it, and on a nurse's OS-dark laptop a stray
 * `@media (prefers-color-scheme: dark)` block silently repaints clinical panels.
 *
 * The regression this guards against is indirect and easy to reintroduce: no app template
 * uses `dark:`, but pointing an `@source` at `storage/framework/views` (Laravel's Blade
 * compile cache) drags the FRAMEWORK's own views — the exception page, the bundled
 * pagination components, the starter-kit welcome page — into Tailwind's scan. Those are
 * littered with `dark:` variants.
 *
 * Two layers, because they fail at different times:
 *   1. source-level  — always runs, catches the @source at review time.
 *   2. artifact-level — runs when a build exists, catches ANY route to a dark rule.
 */
class CompiledCssIsLightOnlyTest extends TestCase
{
    private function appCssPath(): string
    {
        return base_path('resources/css/app.css');
    }

    /**
     * Layer 1 — the Blade compile cache must never be a Tailwind source.
     */
    public function test_app_css_does_not_scan_the_blade_compile_cache(): void
    {
        $css = file_get_contents($this->appCssPath());

        // Strip comments first: the file *documents* this rule in prose, and the prose
        // legitimately names the directory it is warning about.
        $withoutComments = preg_replace('#/\*.*?\*/#s', '', $css);

        $sources = [];
        preg_match_all('/@source\s+[\'"]([^\'"]+)[\'"]/', $withoutComments, $sources);

        // Assert over the whole SET, never inside a foreach. A per-item loop iterates zero
        // times once the offending @source is gone and the test passes having asserted
        // nothing — a guard that silently stops guarding the moment it starts succeeding.
        $offending = array_values(array_filter(
            $sources[1],
            fn (string $source): bool => str_contains($source, 'storage/framework/views')
        ));

        $this->assertSame(
            [],
            $offending,
            'resources/css/app.css declares @source '.json_encode($offending).", which pulls Laravel's ".
            'compiled FRAMEWORK views (exception page, pagination, welcome) into the '.
            'Tailwind scan. Their `dark:` variants then compile into this light-only theme. '.
            'App templates under resources/ are already covered by automatic source detection.'
        );
    }

    /**
     * Layer 2 — the emitted bundle itself carries no dark-scheme rules.
     *
     * Matches BOTH `prefers-color-scheme: dark` and the minified
     * `prefers-color-scheme:dark`; production CSS is minified, so a spaced-only grep
     * reports a false all-clear.
     */
    public function test_built_css_bundles_contain_no_dark_scheme_rules(): void
    {
        $bundles = glob(public_path('build/assets/*.css')) ?: [];

        if ($bundles === []) {
            $this->markTestSkipped('No build artifacts present — run `npm run build` first.');
        }

        foreach ($bundles as $bundle) {
            $contents = file_get_contents($bundle);
            $hits = preg_match_all('/prefers-color-scheme\s*:\s*dark/i', $contents);

            $this->assertSame(
                0,
                $hits,
                basename($bundle)." ships {$hits} `prefers-color-scheme: dark` rule(s). ".
                'This theme is light-only (docs/DESIGN-TOKENS.md). Something is feeding '.
                '`dark:` classes into the Tailwind scan — check the @source lines in '.
                'resources/css/app.css.'
            );
        }
    }

    /**
     * The emitted print bundle must restore handover list markers.
     *
     * Checks what the browser actually loads, because the compile step is where it can
     * go wrong: the minifier rewrites `list-style: disc outside` to `list-style: outside`,
     * and Vue has to emit the `:deep()` selector as an UNSCOPED descendant (the scoped
     * attribute on the ancestor only) or it will not reach the `v-html` handover nodes.
     * Skips until the Print page exists (Phase 6).
     */
    public function test_built_print_css_restores_handover_list_markers(): void
    {
        $bundles = glob(public_path('build/assets/Print-*.css')) ?: [];

        if ($bundles === []) {
            $this->markTestSkipped('No print build artifact present — built in Phase 6.');
        }

        $css = implode('', array_map('file_get_contents', $bundles));

        $this->assertMatchesRegularExpression(
            '/\.print-table\[[^\]]+\]\s+ol\{[^}]*list-style-type:\s*decimal/',
            $css,
            'The printed handover sheet lost ORDERED list numbering. A numbered plan '.
            '("1. wean FiO2, 2. repeat gas...") would print as flat unmarked lines.'
        );

        $this->assertMatchesRegularExpression(
            '/\.print-table\[[^\]]+\]\s+ul\{[^}]*list-style-type:\s*disc/',
            $css,
            'The printed handover sheet lost bulleted list markers.'
        );

        $this->assertMatchesRegularExpression(
            '/\.print-table\[[^\]]+\]\s+ul,\s*\.print-table\[[^\]]+\]\s+ol\{[^}]*padding-left:/',
            $css,
            'Handover lists have no left padding, so `outside` markers will be clipped.'
        );
    }
}
