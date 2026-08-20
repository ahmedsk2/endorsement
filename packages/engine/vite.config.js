import { fileURLToPath } from 'node:url';

import { defineConfig } from 'vite';

/**
 * The Node bundle of `@engine` (P2 Task 23).
 *
 * ## Why a bundle exists at all, when `tsconfig.base.json` says the package emits nothing
 *
 * That comment is about the BROWSER runtime and it still holds: `vite.config.js`'s `@engine` alias
 * points at `src/index.ts`, Vite transpiles the sources it bundles into the app, and a committed
 * `dist/` beside the sources would be a second artifact that can disagree with the one `npm test`
 * and `tsc --noEmit` read. None of that is changed here.
 *
 * What is new is a second runtime with a different constraint. D4 gives the engine two, and the
 * Node one cannot load these sources at all — for two independent reasons, both measured rather
 * than assumed and both recorded in `bin/evaluate.mjs`: `moduleResolution: bundler` makes
 * `import … from './calendar'` a directory import Node's resolver refuses outright, and Node's
 * strip-only TypeScript mode refuses the constructor parameter properties `evaluate.ts` uses.
 * Something must turn the graph into JavaScript, and the honest choice is the bundler the browser
 * build already uses, through the same entry the browser resolves.
 *
 * ## The properties that make this NOT a second artifact
 *
 *  - `dist/` is **gitignored and never committed**, so it cannot drift in the tree. It can only be
 *    stale on a machine that edited the sources without rebuilding — and the CI step rebuilds
 *    before it runs, so the state CI reports on is always freshly derived from the sources.
 *  - The entry is `src/index.ts` — the package's public surface, the exact module `@engine`
 *    resolves to. A bundle of some other entry would be a different question answered.
 *  - No transform of its own: no plugins, no minifier, no `define`. The only thing this config
 *    does that the app build does not is choose a Node target and an ES output.
 *
 * ## `external`
 *
 * The engine imports nothing — not a dependency, not a `node:` builtin — which is CG-10's purity
 * stated as a fact about the module graph rather than as a docblock. The `node:` external below is
 * therefore expected to match nothing today; it is here so that a future `node:` import in `src/`
 * fails at the SOURCE review rather than being silently inlined or shimmed by the bundler into
 * something the browser build would then have to cope with.
 */
export default defineConfig({
    root: fileURLToPath(new URL('.', import.meta.url)),
    publicDir: false,
    logLevel: 'warn',
    build: {
        target: 'node22',
        outDir: 'dist',
        emptyOutDir: true,
        minify: false,
        sourcemap: false,
        reportCompressedSize: false,
        lib: {
            entry: 'src/index.ts',
            formats: ['es'],
            fileName: () => 'engine.mjs',
        },
        rollupOptions: {
            external: [/^node:/],
        },
    },
});
