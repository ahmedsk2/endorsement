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
export * from './duty/windows';
