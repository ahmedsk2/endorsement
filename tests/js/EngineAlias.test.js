import { existsSync } from 'node:fs';
import { isAbsolute, resolve as resolvePath } from 'node:path';

import { describe, it, expect } from 'vitest';
import { resolveConfig } from 'vite';

import { version } from '../../packages/engine/src/index.ts';

// The `@engine` alias, asserted in the same task that adds it and before anything imports it.
//
// Why assert an alias nothing uses yet: D4 gives the conditions engine two runtimes, and this is
// the browser one. A package the bundler cannot resolve is not a browser runtime, and P3's
// workbench would discover that while wiring live hints - the worst possible moment. The three
// ways it breaks are all covered below: the entry is missing; it points at a path that does not
// exist; it points at a real file that is not the package entry.
//
// It goes through Vite's own `resolveConfig()` rather than importing `vite.config.js` directly,
// and that is not fastidiousness - the direct import was WRITTEN FIRST AND FAILED. Vitest
// transforms the config file, so `import.meta.url` inside it is not a `file:` URL and
// `fileURLToPath(new URL(...))` throws "The URL must be of scheme file". Under a real `vite build`
// the config is bundled to a temp file and `import.meta.url` IS a file URL, so a direct import
// tests conditions the bundler never has and would have failed for a reason that says nothing
// about the alias. `resolveConfig()` loads the config the way the build loads it and hands back
// the alias already normalised into the array form the build sees.
//
// WHAT THIS DOES NOT DO, stated rather than implied: it does not bundle an actual
// `import ... from '@engine'`, because nothing imports `@engine` yet, and the alias is
// deliberately absent from `vitest.config.js` - one definition of the alias, in the config the
// browser bundle reads. That the entry LOADS is proved next door, by
// `packages/engine/test/smoke.test.ts` importing it; this file proves the bundler is pointed at
// the same file. The end-to-end proof arrives free in P3, when the workbench's first
// `import ... from '@engine'` either builds or does not.
//
// Proved capable of failing: deleting the `@engine` entry from `vite.config.js` turns the first
// test red; re-pointing it at `./packages/engine/src/nope.ts` turns the second and third red.
describe('the @engine alias', () => {
    async function engineAliasEntries() {
        // 'build', not 'serve': the browser bundle is what AR-03's two-runtime claim rests on.
        const config = await resolveConfig({ configFile: 'vite.config.js', logLevel: 'silent' }, 'build');

        return config.resolve.alias.filter((entry) => entry.find === '@engine');
    }

    it('is in the config Vite itself resolves for a build', async () => {
        expect(await engineAliasEntries()).toHaveLength(1);
    });

    it('resolves to an absolute path that exists on disk', async () => {
        const [entry] = await engineAliasEntries();

        expect(isAbsolute(entry.replacement)).toBe(true);
        expect(existsSync(entry.replacement)).toBe(true);
    });

    it('resolves to the same engine entry the package test imports', async () => {
        const [entry] = await engineAliasEntries();

        // Both sides through node:path, so separator style is not what is being compared.
        expect(resolvePath(entry.replacement))
            .toBe(resolvePath(process.cwd(), 'packages/engine/src/index.ts'));

        expect(version).toBe('0.0.0');
    });
});
