/**
 * `@engine` — the pure conditions engine (Munawib CG-10, design D4).
 *
 * The public surface of this package. It is empty on purpose: P2 Task 2 builds the toolchain
 * and nothing else, so that the first real module (Task 3's `Ymd` core) lands in a tree where
 * `npm test`, `npm run build` and `tsc --noEmit` already run against TypeScript.
 *
 * Three properties this package holds from its first line, each stated where a later author will
 * read it before adding the code that would break it:
 *
 *  - **No `Date`, no instant, no timezone.** Dates are `Ymd` strings and integer civil-date
 *    arithmetic; times are minutes from local midnight. A Node process with `TZ` unset runs at
 *    UTC and a browser at +03:00 does not, and an engine holding no instants cannot have that
 *    bug at all — which is stronger than a test that remembers to set `TZ`.
 *  - **No I/O, no globals, no clock.** `evaluate()` is a pure function of its arguments; "today"
 *    arrives in the evaluation context, computed once by the one converter on the server.
 *  - **No department-varying fact is a literal here.** Weekend days, week start, resolved
 *    holidays and week windows are all parameters.
 *
 * `tsconfig.base.json` sets `lib` to ES2022 with no DOM, so a browser global cannot be reached
 * by accident even though this code ships to the browser.
 */

/**
 * Package version, carried as a value rather than read from `package.json`.
 *
 * A JSON import would need `resolveJsonModule` and would resolve differently under the bundler,
 * under plain Node and under `tsc`, which is three answers to a question worth none.
 */
export const version = '0.0.0';

/**
 * The `Ymd` core (P2 Task 3) and the calendar mirror built on it (P2 Task 5), which re-exports it.
 * Both are surfaced here rather than left importable only by deep path, because `@engine` resolves
 * to THIS file (`vite.config.js`) and a module the package entry does not expose is not part of
 * the browser runtime D4 requires, whatever the test runner can reach.
 */
export * from './calendar';

/**
 * The duty-time core (P2 Task 4): the absolute-minute line, half-open intervals, one person's
 * ordered duties across the carry-in tail, and the windows measured over a horizon. Every
 * condition type consumes these rather than re-deriving them — one definition of when a night
 * call ends, for the same reason `AuditChain::canonical()` has exactly one.
 */
export * from './duty/interval';
export * from './duty/order';
export * from './duty/post-duty-window';
export * from './duty/windows';

/**
 * The CG-10 contract (P2 Task 7): the shapes, the hand-written JSON Schema and the validator that
 * runs it, `evaluate()`, and the sibling `coverage()` that reports the windows a floor could not
 * honestly measure. `registry.ts` carries one entry per CG-07 type key.
 *
 * `evaluate()` is the whole public purpose of this package and is surfaced here rather than left
 * importable only by deep path: `@engine` resolves to THIS file, and a module the package entry
 * does not expose is not part of the browser runtime D4 requires, whatever the test runner can
 * reach.
 */
export * from './contract/types';
export * from './contract/schema';
export * from './contract/validate';
export * from './registry';
export * from './evaluate';
export * from './coverage';

/**
 * CG-05/CG-06's severity model and CG-04's plain-language previews (P2 Task 9).
 *
 * `severity.ts` holds the ONE stamp — a type reports where and why, and `Condition.class` is the
 * only input to how severe — and CG-02's order over it. `messages.ts` is the English sentence table
 * a second language would replace; `preview.ts` routes a condition to its type's sentence and
 * refuses, loudly and in three distinguishable ways, when it cannot.
 *
 * The per-type modules under `conditions/` are deliberately NOT re-exported: they each export
 * `PARAMS_SCHEMA`, `readParams` and `preview` under those same names, and a star re-export would
 * collide. The registry is how a caller reaches one, which is also the only way a caller should —
 * a type resolved by import path is a type resolved without the catalog knowing.
 */
export * from './severity';
export * from './messages';
export * from './preview';

/**
 * CG-08's three preset bundles (P2-2 Task 21), as data in the package.
 *
 * Surfaced here for the same reason `evaluate()` is: `@engine` resolves to THIS file, and a module
 * the package entry does not expose is not part of the browser runtime D4 requires, whatever the
 * test runner can reach. ST-01's setup wizard imports these into `conditions` in P3; until then a
 * preset is simply a list of condition rows `evaluate()` can be called with.
 */
export * from './presets';
