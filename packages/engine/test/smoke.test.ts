import { describe, it, expect } from 'vitest';

import { version } from '../src/index';

// The first TypeScript in this repository, and the file that proves the toolchain exists at all.
//
// Its vacuity mode is the reason it was written before any config change: `vitest.config.js`
// includes `tests/js/**/*.test.js` and nothing else, so a `.ts` file under `packages/` is not
// run — and a suite that silently does not run a new file reports the same green, with the same
// exit code, as a suite that ran it and passed. This file was watched NOT RUNNING (25 files,
// 237 tests) before `packages/*/test/**/*.test.ts` was added to the include list, and watched
// failing (no `../src/index`) immediately after. Neither observation is available from the
// finished tree, which is why both are recorded here.
describe('packages/engine', () => {
    it('exports a version the test runner can actually import', () => {
        expect(version).toBe('0.0.0');
    });
});
