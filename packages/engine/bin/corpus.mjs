#!/usr/bin/env node
/**
 * The CI harness for the Node entrypoint (P2 Task 23, owner decision Y).
 *
 * ```
 * npm run build:engine && npm run engine:corpus
 * ```
 *
 * ## What this is, named honestly — because the phase table's wording is not
 *
 * The design doc's P2 row once said *"the CI cross-validation job"*, and Task 1 rewrote it
 * because that name overstates what P2 can ship. **This harness is single-implementation
 * regression coverage (NF-08/QA-01), not cross-validation.** There is one conditions engine; a
 * corpus asserted against it says the engine still does what it did yesterday, which is worth
 * having and is not the same claim as two implementations agreeing.
 *
 * The repository does have one genuine cross-implementation check and it is worth knowing which:
 * `test/golden.test.ts` asserts the TypeScript calendar mirror against
 * `tests/fixtures/calendar/golden.json`, every value in which was produced by running
 * `App\Support\Calendar` in PHP. Two implementations of one fact, and a divergence fails the
 * build. It runs under `npm test` and needs nothing from this file. §4.3's real cross-validation
 * — the engine against the CP-SAT solver's evaluation mode — arrives in P4 with the solver.
 *
 * ## What it therefore adds that CI did not already have
 *
 * `npm test` already runs every one of these fixtures through `evaluate()` and `coverage()`
 * (`test/conditions.test.ts`), so re-asserting the ANSWERS is not the point and would be the
 * duplicate step this task was warned against. Three things are genuinely new, and each is a
 * property of the RUNTIME rather than of the rules:
 *
 *  1. **The package runs under plain Node, outside a bundler and outside the test runner.**
 *     Vitest transpiles TypeScript per file through Vite and runs it in jsdom; neither answers
 *     whether the module graph survives a real bundle or whether the code reaches a global that
 *     only jsdom provides. D4 gives this engine two runtimes and until now CI exercised one.
 *  2. **The PUBLIC SURFACE is exercised.** This harness and `bin/evaluate.mjs` reach the package
 *     only through `src/index.ts` — the exact module `@engine` resolves to and the only thing
 *     P3's workbench will import. Every test in `test/` imports by deep path (`../src/evaluate`),
 *     so before this file, `index.ts` re-exporting `evaluate` at all was asserted by nothing:
 *     `smoke.test.ts` and `tests/js/EngineAlias.test.js` between them read one export, `version`.
 *  3. **The CLI contract is exercised per case** — stdin, stdout, exit code — rather than
 *     described. Every fixture goes through a real process.
 *
 * ## Phase 2 is a MEASUREMENT, and it is gated on not being vacuous
 *
 * NF-01 gives `evaluate()` a 100 ms budget. The plan measures it here rather than asserting it,
 * and records the number whether or not it is met — *a budget quietly missed is worse than a
 * budget missed out loud* — so the timing itself does not fail the build. A step that cannot fail
 * is worse than none, so phase 2 does not consist only of a timing: it **asserts the measured run
 * was not empty**, because a benchmark of an engine that resolved no condition and produced no
 * violation measures process startup and reports it as headroom. Phase 1 is the real gate.
 *
 * ## No rule logic lives here either
 *
 * The synthetic month below is INPUT — people, days, slots, duties and twenty-two condition rows
 * with parameters. It decides nothing about whether any of them is satisfied; every answer comes
 * back from the package.
 */

import { spawnSync } from 'node:child_process';
import { readdirSync, readFileSync } from 'node:fs';
import { join } from 'node:path';
import { fileURLToPath } from 'node:url';
import { deepStrictEqual } from 'node:assert';

const ENTRYPOINT = fileURLToPath(new URL('./evaluate.mjs', import.meta.url));
const BUNDLE = new URL('../dist/engine.mjs', import.meta.url);
const FIXTURE_DIR = fileURLToPath(new URL('../test/fixtures/conditions/', import.meta.url));

/**
 * The compiled package, loaded FIRST because the synthetic month below is built with its own
 * `Ymd` core.
 *
 * That is not convenience, it is Decision B and `CalendarIsTheOnlyConverterTest`: nothing under
 * `packages/` holds an instant or a timezone, and the allow-list for that scan is empty in both
 * directions. The first draft of this file walked the calendar with the UTC epoch helpers and
 * carried a docblock explaining why a benchmark harness was different. It is not different — the
 * guard found it, and the fix was better than the excuse: `addDays`, `datesBetween` and
 * `isoWeekday` are the engine's own, so the benchmark's dates are produced by the same civil-date
 * arithmetic the evaluators consume. Nothing is asserted about them here, so there is no
 * circularity to buy.
 *
 * The guard then found it a SECOND time, in the sentence above: the needle list is substrings over
 * whole files, so naming the forbidden call in prose is an offence in exactly the way naming it in
 * code is. A docblock is scanned source. It is written around here rather than allow-listed.
 */
const engine = await import(BUNDLE.href).catch((error) => {
    if (error?.code === 'ERR_MODULE_NOT_FOUND') {
        process.stderr.write(
            `The compiled engine is missing at ${fileURLToPath(BUNDLE)}.\nBuild it first: npm run build:engine\n`,
        );
        process.exit(2);
    }

    throw error;
});

const { addDays, datesBetween, isoWeekday, parseYmd } = engine;

const failures = [];

function fail(what, detail) {
    failures.push(`${what}\n${detail.replace(/^/gm, '    ')}`);
}

// -------------------------------------------------------------------------------------------
// Phase 1 — every fixture through the compiled entrypoint, as a process.
// -------------------------------------------------------------------------------------------

function loadFixtures() {
    const names = readdirSync(FIXTURE_DIR)
        .filter((name) => name.endsWith('.json'))
        .sort();

    // A harness iterating an empty directory is green for the wrong reason, and a moved or
    // renamed fixture folder is exactly how one gets there — `test/conditions.test.ts` carries
    // the same guard for the same reason, one runtime along.
    if (names.length === 0) {
        throw new Error(`No fixtures found in ${FIXTURE_DIR}. The corpus cannot be empty.`);
    }

    return names.map((name) => ({
        file: name,
        fixture: JSON.parse(readFileSync(join(FIXTURE_DIR, name), 'utf8')),
    }));
}

function runEntrypoint(request) {
    return spawnSync(process.execPath, [ENTRYPOINT], {
        input: JSON.stringify(request),
        encoding: 'utf8',
        maxBuffer: 64 * 1024 * 1024,
    });
}

function checkFixture({ file, fixture }) {
    const result = runEntrypoint({
        schedule: fixture.schedule,
        context: fixture.context,
        conditions: fixture.conditions,
    });

    if (result.status !== 0) {
        fail(`${file}: the entrypoint exited ${result.status}`, result.stderr || '(no stderr)');

        return;
    }

    let answer;

    try {
        answer = JSON.parse(result.stdout);
    } catch (error) {
        fail(`${file}: the entrypoint did not write JSON`, `${error.message}\n${result.stdout.slice(0, 400)}`);

        return;
    }

    // `expected` is authored in whatever order reads best in the file; the engine's output is
    // totally ordered by `sortViolations`. Sorting the expectation with the package's OWN
    // comparator is what `test/conditions.test.ts` does, and using a second one here would be a
    // second definition of the order — which is the failure class this phase spent its risk
    // budget avoiding.
    try {
        deepStrictEqual(answer.violations, engine.sortViolations(fixture.expected));
    } catch (error) {
        fail(`${file}: violations differ`, error.message);
    }

    if (fixture.expectedCoverage !== undefined) {
        try {
            deepStrictEqual(answer.coverage, fixture.expectedCoverage);
        } catch (error) {
            fail(`${file}: coverage differs`, error.message);
        }
    }
}

/**
 * Every implemented catalog key is exercised by at least one case the harness actually ran.
 *
 * This is the harness's own non-vacuity guard rather than a restatement of Task 8's parity guard:
 * that one compares the registry against CG-07's table and says nothing about whether a corpus
 * touches a type. A type whose fixtures were all deleted would leave this run green and shorter,
 * which is indistinguishable from a run that checked it.
 */
function checkEveryImplementedTypeIsExercised(loaded) {
    const exercised = new Set(
        loaded.flatMap(({ fixture }) => fixture.conditions.map((condition) => condition.typeKey)),
    );

    const missing = engine.CATALOG.filter((entry) => entry.implemented && !exercised.has(entry.typeKey)).map(
        (entry) => entry.typeKey,
    );

    if (missing.length > 0) {
        fail('the corpus does not exercise every implemented type', missing.join('\n'));
    }
}

/**
 * The entrypoint's REFUSALS, asserted rather than described.
 *
 * `bin/evaluate.mjs` documents four exit codes and the corpus above exercises exactly one of them,
 * because every fixture is well-formed. An exit code nothing asserts is this codebase's most
 * expensive recurring defect one layer along — rulings 41 and 49, three refusals in two slices
 * that each *looked* correct in review and each did nothing — and a CLI is where it costs most,
 * since the caller reading a wrong code carries on as though the request had been evaluated.
 *
 * The last case is the load-bearing one: **finding violations is exit 0.** A CLI whose exit code
 * means both "I could not do this" and "I did it and the answer was non-empty" is one every caller
 * has to special-case, and the caller that forgets treats a malformed context as a clean schedule.
 */
function checkRefusals(sample) {
    const request = {
        schedule: sample.schedule,
        context: sample.context,
        conditions: sample.conditions,
    };

    const raw = (input) =>
        spawnSync(process.execPath, [ENTRYPOINT], { input, encoding: 'utf8', maxBuffer: 8 * 1024 * 1024 });

    const cases = [
        ['empty stdin', raw(''), 2, /reads one CG-10 JSON object on stdin/],
        ['stdin that is not JSON', raw('{ not json'), 2, /not valid JSON/],
        ['a JSON array rather than a request object', raw('[]'), 2, /must be a JSON object/],
        [
            'a request missing every argument',
            raw('{}'),
            2,
            /schedule: is required[\s\S]*context: is required[\s\S]*conditions: must be an array/,
        ],
        [
            'a schedule that is not the contract shape',
            raw(JSON.stringify({ ...request, schedule: { horizon: {}, duties: [] } })),
            2,
            /schedule\/horizon: is missing the required property "from"/,
        ],
        [
            'a condition naming a type key no catalog row carries',
            raw(JSON.stringify({ ...request, conditions: [{ ...request.conditions[0], typeKey: 'no_such_type' }] })),
            3,
            /no catalog row carries/,
        ],
        [
            'a condition naming the one catalog row this engine does not implement',
            raw(
                JSON.stringify({
                    ...request,
                    conditions: [{ ...request.conditions[0], typeKey: 'forbidden_transition' }],
                }),
            ),
            3,
            /does not implement/,
        ],
        ['a well-formed request that DOES produce violations', raw(JSON.stringify(request)), 0, /^$/],
    ];

    for (const [what, result, code, pattern] of cases) {
        if (result.status !== code) {
            fail(`the entrypoint given ${what} exited ${result.status}, not ${code}`, result.stderr || '(no stderr)');

            continue;
        }

        if (!pattern.test(result.stderr.trim())) {
            fail(`the entrypoint given ${what} did not say why`, result.stderr || '(no stderr)');
        }
    }

    // The positive half of the last case, stated separately so a silent success cannot pass for
    // one that reported something: this fixture expects violations and the process must have
    // written them while exiting 0.
    const answer = JSON.parse(raw(JSON.stringify(request)).stdout);

    if (answer.violations.length === 0) {
        fail('the refusal sample produces no violations', `${sample.name} was chosen because it does`);
    }
}

// -------------------------------------------------------------------------------------------
// Phase 2 — NF-01, measured on a synthetic month with all 22 implemented types active.
// -------------------------------------------------------------------------------------------

// Parsed through the package's own `parseYmd`, so a typo in one of these four is refused here
// rather than travelling into a context the schema then rejects one layer along.
const MONTH_FROM = parseYmd('2026-03-01');
const MONTH_TO = parseYmd('2026-03-31');
const TAIL_FROM = parseYmd('2026-02-01');
const AHEAD_TO = parseYmd('2026-04-15');
const HOLIDAY = parseYmd('2026-03-17');

const LEVELS = ['R1', 'R2', 'R3', 'R4'];
const UNITS = ['PICU', 'NICU'];

const WEEKEND_DAYS = [5, 6];

const SLOTS = [
    {
        key: 'day',
        kind: 'call',
        unitKey: 'PICU',
        cadence: 'daily',
        spanDays: 1,
        startMinute: 480,
        endMinute: 1200,
        crossesMidnight: false,
        countsHours: true,
        tallyKey: 'days',
    },
    {
        key: 'night',
        kind: 'call',
        unitKey: 'PICU',
        cadence: 'daily',
        spanDays: 1,
        startMinute: 1200,
        endMinute: 480,
        crossesMidnight: true,
        countsHours: true,
        tallyKey: 'nights',
    },
    {
        key: 'backup',
        kind: 'backup',
        unitKey: 'NICU',
        cadence: 'daily',
        spanDays: 1,
        startMinute: 480,
        endMinute: 1200,
        crossesMidnight: false,
        countsHours: false,
        tallyKey: 'backup',
    },
];

function syntheticPeople() {
    return Array.from({ length: 20 }, (unused, index) => {
        const key = `p-${String(index + 1).padStart(2, '0')}`;

        return {
            key,
            levelSpans: [{ key: LEVELS[index % LEVELS.length], from: '2025-07-01', to: '2027-06-30' }],
            unitSpans: [{ key: UNITS[index % UNITS.length], from: '2025-07-01', to: '2027-06-30' }],
            leaveDays: index % 5 === 0 ? [addDays(MONTH_FROM, index % 20), addDays(MONTH_FROM, (index % 20) + 1)] : [],
            unwantedDays: index % 4 === 0 ? [addDays(MONTH_FROM, (index * 3) % 28)] : [],
            eligibleDays: [],
            external: index === 19,
            joinedAt: '2025-07-01',
            priorCredits: {},
        };
    });
}

function syntheticWeeks(from, to) {
    const weeks = [];

    // The department week starts on Sunday (ISO 7), which is `weekStartIsoDay` below. The first
    // and last weeks of a period are clipped to the period, which is the shape `Calendar::weeksIn()`
    // produces server-side and the reason `Week` carries four dates rather than two.
    let cursor = from;

    while (cursor <= to) {
        const offset = (isoWeekday(cursor) % 7);
        const startsOn = addDays(cursor, -offset);
        const endsOn = addDays(startsOn, 6);

        weeks.push({
            startsOn,
            endsOn,
            clippedStartsOn: startsOn < from ? from : startsOn,
            clippedEndsOn: endsOn > to ? to : endsOn,
        });

        cursor = addDays(endsOn, 1);
    }

    return weeks;
}

function syntheticContext() {
    const days = datesBetween(TAIL_FROM, AHEAD_TO).map((date) => ({
        date,
        isoWeekday: isoWeekday(date),
        dayType: date === HOLIDAY ? 'HOL' : WEEKEND_DAYS.includes(isoWeekday(date)) ? 'WE' : 'WD',
        periodKey: date >= MONTH_FROM && date <= MONTH_TO ? 'block-09' : date < MONTH_FROM ? 'block-08' : 'block-10',
        holidays: date === HOLIDAY ? [{ key: 'eid-al-fitr', year: 2026 }] : [],
    }));

    return {
        timezone: 'Asia/Riyadh',
        weekStartIsoDay: 7,
        weekendDays: WEEKEND_DAYS,
        today: MONTH_FROM,
        days,
        periods: [
            {
                key: 'block-08',
                startsOn: TAIL_FROM,
                endsOn: addDays(MONTH_FROM, -1),
                weeks: syntheticWeeks(TAIL_FROM, addDays(MONTH_FROM, -1)),
            },
            { key: 'block-09', startsOn: MONTH_FROM, endsOn: MONTH_TO, weeks: syntheticWeeks(MONTH_FROM, MONTH_TO) },
            {
                key: 'block-10',
                startsOn: addDays(MONTH_TO, 1),
                endsOn: AHEAD_TO,
                weeks: syntheticWeeks(addDays(MONTH_TO, 1), AHEAD_TO),
            },
        ],
        people: syntheticPeople(),
        slots: SLOTS,
        clinics: [
            {
                key: 'picu-monday-am',
                unitKey: 'PICU',
                isoWeekday: 1,
                session: 'AM',
                active: true,
                attendeeMode: 'rotators',
                attendeeLevelKeys: [],
                attendeePersonKeys: [],
            },
        ],
        historyAvailableFrom: '2025-07-01',
        priorDuties: dutiesOver(datesBetween(addDays(MONTH_FROM, -14), addDays(MONTH_FROM, -1)), 0),
        followingDuties: dutiesOver(datesBetween(addDays(MONTH_TO, 1), addDays(MONTH_TO, 7)), 900),
    };
}

/** One duty per slot per date, people taken round-robin so the load spreads unevenly enough to fire. */
function dutiesOver(dates, seed) {
    const duties = [];
    let n = seed;

    for (const date of dates) {
        for (const slot of SLOTS) {
            duties.push({ personKey: `p-${String((n % 20) + 1).padStart(2, '0')}`, date, slotKey: slot.key });
            n += 1;
        }
    }

    return duties;
}

/**
 * All twenty-two implemented type keys, active, with parameters a department could plausibly set.
 *
 * The numbers are not policy and must not be read as any: §37 still owes this project its real
 * duty-hour and equity figures, and owner decision AB is explicit that a plausible-looking default
 * *"looks authoritative on a gate screen"*. These exist to make twenty-two evaluators do work on a
 * month-sized input so NF-01 has something to measure — nothing here ships as a preset.
 */
function syntheticConditions() {
    const row = (typeKey, params, klass = 'soft-top') => ({
        id: `nf01-${typeKey}`,
        typeKey,
        class: klass,
        active: true,
        params,
    });

    return [
        row('overlap_block', {}, 'hard'),
        row('vacation_block', {}, 'hard'),
        row('eligibility', { allowed: { day: { levelKeys: LEVELS }, night: { levelKeys: ['R2', 'R3', 'R4'] } } }, 'hard'),
        row('unwanted_day_block', {}),
        row('onboarding_grace', { days: 14 }, 'hard'),
        row('dow_restriction', { days: [5] }),
        row('clinic_conflict', { variant: 'post_call_and_same_day' }, 'hard'),
        row('same_unit_conflict', { units: UNITS }, 'hard'),
        row('min_gap', { value: 48, unit: 'hours', kinds: ['call'] }, 'hard'),
        row('post_duty_exclusion', { hours: 24, from: ['call'], to: ['call'] }, 'hard'),
        row('consecutive_max', { count: 2, unit: 'days', kinds: ['call'] }, 'hard'),
        row('count_max', { count: 6, window: 'period', kinds: ['call'] }),
        row('count_min', { count: 2, window: 'period', kinds: ['call'] }),
        row('target_per_period', { targets: { R1: 6, R2: 5, R3: 4, R4: 3 } }),
        row('composition', { targets: { R1: { WD: 4, WE: 2 }, R2: { WD: 3, WE: 2 }, R3: { WD: 3, WE: 1 }, R4: { WD: 2, WE: 1 } } }),
        row('max_gap', { days: 12, kinds: ['call'] }),
        row('free_day_min', { n: 1, leaveCountsAsFree: true }),
        row('rolling_hours_max', { hours: 80, windowDays: 7 }),
        row('call_frequency_max', { n: 3, windowDays: 7 }),
        row('fairness_distribution', { quantity: 'nights', mode: 'deviation', excludeExternal: true }),
        row('holiday_equity', { holidays: ['eid-al-fitr'], lookbackYears: 0 }),
        row('we_pairing', { preferredPairs: [{ first: 5, second: 6 }] }),
    ];
}

function measure() {
    const context = syntheticContext();
    const schedule = {
        horizon: { from: MONTH_FROM, to: MONTH_TO, evaluableFrom: TAIL_FROM, evaluableTo: AHEAD_TO },
        duties: dutiesOver(datesBetween(MONTH_FROM, MONTH_TO), 300),
    };
    const conditions = syntheticConditions();

    const active = new Set(conditions.map((condition) => condition.typeKey));
    const implemented = engine.CATALOG.filter((entry) => entry.implemented).map((entry) => entry.typeKey);
    const absent = implemented.filter((typeKey) => !active.has(typeKey));

    if (absent.length > 0) {
        fail('the NF-01 case does not activate every implemented type', absent.join('\n'));

        return null;
    }

    const violations = engine.evaluate(schedule, context, conditions);
    const firing = new Set(violations.map((violation) => violation.conditionId));

    // The vacuity gate. A measured run that produced nothing timed twenty-two lookups over an
    // empty world and would report the result as headroom.
    if (firing.size < 2) {
        fail(
            'the NF-01 case is vacuous',
            `${violations.length} violations from ${firing.size} conditions — a benchmark of an engine ` +
                'that did nothing measures process startup, not NF-01.',
        );

        return null;
    }

    // Nine samples after a warm-up, reported as a median with its range. One sample is not a
    // measurement on a machine that is also running a browser: the first pass of this benchmark
    // read 76 ms and 103 ms on the same tree, minutes apart, which is most of the budget in
    // scheduler noise. Reporting the spread is what stops the number being read as a precision it
    // does not have.
    const timed = (run) => {
        run();

        const samples = [];

        for (let i = 0; i < 9; i += 1) {
            const at = process.hrtime.bigint();

            run();
            samples.push(Number(process.hrtime.bigint() - at) / 1e6);
        }

        samples.sort((a, b) => a - b);

        return { median: samples[4], best: samples[0], worst: samples[8] };
    };

    return {
        duties: schedule.duties.length,
        people: context.people.length,
        slots: SLOTS.length,
        types: conditions.length,
        violations: violations.length,
        conditionsFiring: firing.size,
        evaluate: timed(() => engine.evaluate(schedule, context, conditions)),
        evaluateAndCoverage: timed(() => {
            engine.evaluate(schedule, context, conditions);
            engine.coverage(schedule, context, conditions);
        }),
    };
}

// -------------------------------------------------------------------------------------------

const loaded = loadFixtures();

for (const entry of loaded) {
    checkFixture(entry);
}

checkEveryImplementedTypeIsExercised(loaded);

const refusalSample = loaded.find(({ fixture }) => fixture.expected.length > 0);

if (refusalSample === undefined) {
    fail('no fixture in the corpus expects a violation', 'the refusal checks need one that does');
} else {
    checkRefusals(refusalSample.fixture);
}

process.stdout.write(
    `corpus: ${loaded.length} fixtures through ${ENTRYPOINT.replace(/\\/g, '/')}, one process each\n` +
        'entrypoint: eight refusal and success cases asserted by exit code and message\n',
);

const measured = measure();

if (measured !== null) {
    const ms = (value) => `${value.toFixed(1)} ms`;

    process.stdout.write(
        `NF-01: ${measured.duties} duties (${measured.people} people x ${measured.slots} slots x 31 days), ` +
            `${measured.types} types active, ${measured.violations} violations from ` +
            `${measured.conditionsFiring} conditions\n` +
            `NF-01: evaluate() median ${ms(measured.evaluate.median)} ` +
            `(best ${ms(measured.evaluate.best)}, worst ${ms(measured.evaluate.worst)}) ` +
            `against a 100 ms budget — ${measured.evaluate.median <= 100 ? 'MET' : 'MISSED'}\n` +
            `NF-01: evaluate() + coverage() median ${ms(measured.evaluateAndCoverage.median)} ` +
            '(what the entrypoint actually does per request; coverage() is a second full traversal)\n' +
            'NF-01: this case is deliberately violation-DENSE — duties are dealt round-robin, so ' +
            'spacing\n       and cap types fire on most of the month. A schedule a department would ' +
            'publish\n       produces a handful of findings, not hundreds, so the number above is an ' +
            'upper bound.\n' +
            'NF-01 is MEASURED here and does not fail the build. The gate above it does.\n',
    );
}

if (failures.length > 0) {
    process.stderr.write(`\n${failures.length} failure(s):\n\n${failures.join('\n\n')}\n`);
    process.exitCode = 1;
} else {
    process.stdout.write('OK\n');
}
