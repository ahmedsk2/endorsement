#!/usr/bin/env node
/**
 * The Node entrypoint of `@engine` (P2 Task 23, owner decision Y).
 *
 * ```
 * node packages/engine/bin/evaluate.mjs < request.json > response.json
 * ```
 *
 * **Accepts** one JSON object on stdin, carrying CG-10's three arguments under their own names:
 *
 * ```json
 * { "schedule": <Schedule>, "context": <EvaluationContext>, "conditions": [<Condition>, …] }
 * ```
 *
 * **Returns** one JSON object on stdout:
 *
 * ```json
 * { "violations": [<Violation>, …], "coverage": [<CoverageReport>, …] }
 * ```
 *
 * `violations` is CG-10's array unchanged — Decision D's *"the return type does not widen"* is
 * about `evaluate()`'s return type and is untouched here. `coverage` is the SIBLING function's
 * output travelling in the same envelope, not a half-violation smuggled into the list: a caller
 * that reads only `violations` sees exactly what `evaluate()` returns, and one that wants to know
 * which windows could not be honestly measured reads the other key. Both come from a single
 * `runConditions()` traversal inside the package, so they cannot disagree about one evaluation.
 *
 * ## Owner decision Y: this is what P2 ships instead of a container
 *
 * `services/engine` is P3, where CG-05's publish gate is its first real caller. A container
 * deployed with nothing calling it can be verified *running* and never verified *working*. This
 * file deploys nothing: it proves the compiled package runs outside a bundler, under plain Node,
 * through the same public entry the browser resolves `@engine` to.
 *
 * ## Why it imports `../dist/engine.mjs` and not `../src/index.ts`
 *
 * Node cannot load the sources, for two independent reasons, both measured on this machine
 * (Node v24.15.0) rather than assumed:
 *
 *  1. `tsconfig.base.json` sets `moduleResolution: bundler`, so `src/index.ts` imports
 *     `'./calendar'` — a directory, extensionless. Node's ESM resolver refuses it:
 *     `ERR_UNSUPPORTED_DIR_IMPORT`. This fires first and would fire even on a JavaScript tree.
 *  2. Node's strip-only TypeScript mode refuses constructor parameter properties, which
 *     `evaluate.ts` uses for `UnknownConditionTypeError`/`UnimplementedConditionTypeError`:
 *     `ERR_UNSUPPORTED_TYPESCRIPT_SYNTAX: TypeScript parameter property is not supported in
 *     strip-only mode`.
 *
 * So a bundle is not a convenience here, it is the only way this runtime exists at all.
 * `vite.config.js` beside this file builds it with the app's own bundler from the app's own entry,
 * into a gitignored `dist/` that CI rebuilds before every run. If it is missing, this file says so
 * in one sentence naming the command, rather than letting Node's `ERR_MODULE_NOT_FOUND` read like
 * a broken checkout.
 *
 * ## Exit codes, and the one that is deliberately NOT here
 *
 * | Code | Meaning |
 * |---|---|
 * | 0 | The request was evaluated. **Violations found or not — that is data, not failure.** |
 * | 2 | The input is not the CG-10 contract: unreadable stdin, bad JSON, or a schema failure. |
 * | 3 | The engine refused the request: a condition names a type key it cannot resolve. |
 * | 1 | Anything else — a bug in the engine, reported with its stack. |
 *
 * **There is no "violations were found" exit code, and that is the load-bearing one.** A CLI whose
 * exit code means both *"I could not do this"* and *"I did it and the answer was non-empty"* is a
 * CLI every caller has to special-case, and the caller that forgets treats a malformed context as
 * a clean schedule. P3's publish gate decides what a violation means; this file only reports one.
 *
 * 2 and 3 are separated for the reason `evaluate()` raises two distinguishable errors rather than
 * one: *"your JSON is the wrong shape"* and *"your condition names a type this engine does not
 * implement"* are repaired in different places by different people.
 *
 * ## No rule logic lives here
 *
 * This file resolves nothing, decides nothing and defaults nothing. It reads bytes, validates them
 * against the contract the package already owns, calls `evaluate()` and `coverage()`, and writes
 * bytes. `RulesLiveOnlyInTheEngineTest` guards the PHP side of that boundary; the guard for this
 * side is that the file has no branch in it a rule could hide in.
 */

import { readFileSync } from 'node:fs';
import { fileURLToPath } from 'node:url';

const BUNDLE = new URL('../dist/engine.mjs', import.meta.url);

const EXIT_OK = 0;
const EXIT_BUG = 1;
const EXIT_BAD_REQUEST = 2;
const EXIT_REFUSED = 3;

/** Read stdin to the end. Synchronous on purpose: this process does one thing and then exits. */
function readStdin() {
    try {
        return readFileSync(0, 'utf8');
    } catch (error) {
        throw new BadRequest(`Could not read the request from stdin: ${error.message}`);
    }
}

/** The input is not the CG-10 contract. */
class BadRequest extends Error {}

function loadEngine() {
    try {
        return import(BUNDLE.href);
    } catch (error) {
        return Promise.reject(error);
    }
}

function parseRequest(raw) {
    if (raw.trim() === '') {
        throw new BadRequest(
            'The request is empty. This entrypoint reads one CG-10 JSON object on stdin: ' +
                '{ "schedule": …, "context": …, "conditions": [ … ] }.',
        );
    }

    try {
        return JSON.parse(raw);
    } catch (error) {
        throw new BadRequest(`The request is not valid JSON: ${error.message}`);
    }
}

/**
 * Validate the three arguments against the contract's own `$defs`, and report EVERY failure rather
 * than the first.
 *
 * A caller repairing a payload one message at a time is a caller running this five times to learn
 * five things it could have learnt once, and the shape of that payload is generated by a PHP
 * context builder rather than typed by a person — so the whole list is what a fix is written
 * against.
 */
function validateRequest(engine, request) {
    if (request === null || typeof request !== 'object' || Array.isArray(request)) {
        throw new BadRequest('The request must be a JSON object with "schedule", "context" and "conditions".');
    }

    const failures = [];
    // `validate()` reports a path from the def it was given — `#/Schedule/horizon`. The caller
    // does not think in def names, it thinks in the request keys it wrote, so the def head is
    // replaced by the argument name: `schedule/horizon`. A path a reader cannot find in their own
    // payload is a message they have to translate before they can act on it.
    const note = (where, errors) => {
        for (const error of errors) {
            failures.push(`  ${where}${error.path.replace(/^#\/[A-Za-z]+/, '')}: ${error.message}`);
        }
    };

    if (!('schedule' in request)) {
        failures.push('  schedule: is required');
    } else {
        note('schedule', engine.validate('Schedule', request.schedule));
    }

    if (!('context' in request)) {
        failures.push('  context: is required');
    } else {
        note('context', engine.validate('EvaluationContext', request.context));
    }

    if (!Array.isArray(request.conditions)) {
        failures.push('  conditions: must be an array of Condition objects');
    } else {
        request.conditions.forEach((condition, index) => {
            note(`conditions[${index}]`, engine.validate('Condition', condition));
        });
    }

    if (failures.length > 0) {
        throw new BadRequest(`The request does not satisfy the CG-10 contract:\n${failures.join('\n')}`);
    }
}

async function main() {
    let engine;

    try {
        engine = await loadEngine();
    } catch (error) {
        if (error?.code === 'ERR_MODULE_NOT_FOUND') {
            process.stderr.write(
                `The compiled engine is missing at ${fileURLToPath(BUNDLE)}.\n` +
                    'Build it first: npm run build:engine\n',
            );

            return EXIT_BAD_REQUEST;
        }

        throw error;
    }

    let request;

    try {
        request = parseRequest(readStdin());
        validateRequest(engine, request);
    } catch (error) {
        if (error instanceof BadRequest) {
            process.stderr.write(`${error.message}\n`);

            return EXIT_BAD_REQUEST;
        }

        throw error;
    }

    let violations;
    let report;

    try {
        violations = engine.evaluate(request.schedule, request.context, request.conditions);
        report = engine.coverage(request.schedule, request.context, request.conditions);
    } catch (error) {
        // The two the engine raises deliberately, by name rather than by instance: this file
        // imports a bundle, and `instanceof` across a module boundary is a promise about identity
        // that a second copy of the graph would quietly break.
        if (error?.name === 'UnknownConditionTypeError' || error?.name === 'UnimplementedConditionTypeError') {
            process.stderr.write(`${error.message}\n`);

            return EXIT_REFUSED;
        }

        throw error;
    }

    process.stdout.write(`${JSON.stringify({ violations, coverage: report })}\n`);

    return EXIT_OK;
}

main().then(
    (code) => {
        process.exitCode = code;
    },
    (error) => {
        process.stderr.write(`${error?.stack ?? error}\n`);
        process.exitCode = EXIT_BUG;
    },
);
