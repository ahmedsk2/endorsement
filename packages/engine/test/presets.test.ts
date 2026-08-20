import { readFileSync } from 'node:fs';
import { join } from 'node:path';

import { describe, expect, it } from 'vitest';

import { datesBetween, isoWeekday, parseYmd, type Ymd } from '../src/calendar/ymd';
import { effectiveRule } from '../src/conditions/free_day_min';
import { effectiveCap } from '../src/conditions/rolling_hours_max';
import type { Condition, Day, EvaluationContext, Person, Schedule } from '../src/contract/types';
import { validate, validateAgainst } from '../src/contract/validate';
import { evaluate } from '../src/evaluate';
import { PRESETS, PRESET_MANIFEST, presetFor, type Preset } from '../src/presets';
import { registryEntry } from '../src/registry';
import { withoutComments } from './support/source';

/**
 * CG-08's three preset bundles and the manifest that has to fail in BOTH directions (P2-2 Task 21).
 *
 * ## Why a manifest at all, and why both directions is the whole of it
 *
 * A manifest saying *"this preset contains these types"* passes trivially if nothing checks the
 * reverse — it is satisfied by any preset that contains a SUPERSET, including one whose conditions
 * have all been deleted while the claim stayed behind. That is this phase's most expensive
 * recurring shape: **a claim asserted where it matches and nowhere where it must not**, seven
 * instances across P2-2 alone. So the two directions are separate cases with separate names:
 *
 *  - **preset → catalog**: a type key a preset names that the registry does not implement.
 *  - **manifest → preset**: a type key the manifest claims that the preset does not carry.
 *  - **preset → manifest**: a row added to a preset that the manifest does not declare.
 *
 * Each was PLANTED. See the commit message for the output of each red run.
 *
 * ## The numbers are a SECOND SOURCE, not a second assertion
 *
 * The five ACGME figures are parsed out of CG-08's own sentence in `docs/munawib/SPEC.md` and
 * compared against the preset's parameters. That is `catalog-parity.test.ts`'s device — a second
 * SOURCE rather than a second implementation, which is `UnitMergeCoversEveryUnitReferenceTest`'s —
 * and it is what makes *"the ACGME numbers are sourced rather than invented"* a machine-checked
 * claim instead of a sentence in a docblock. If the spec's numbers move, the preset fails until
 * somebody moves them here too.
 *
 * ## And the parameterisation is checked against the SCHEMA, never against the prose
 *
 * Every preset condition's `params` is validated against its type's real `PARAMS_SCHEMA` out of the
 * registry, and every draft's awaited list is compared against that schema's own `required` array.
 * Several schemas changed during implementation; the prose in CG-08 and in this plan did not move
 * with them, and a preset written from the prose would validate against nothing.
 */

const SPEC_PATH = join(import.meta.dirname, '..', '..', '..', 'docs', 'munawib', 'SPEC.md');
const PRESET_DIR = join(import.meta.dirname, '..', 'src', 'presets');

const typeKeysIn = (preset: Preset): string[] =>
    [...preset.conditions.map((row) => row.typeKey), ...preset.drafts.map((draft) => draft.typeKey)].sort();

const acgme = presetFor('preset:acgme') as Preset;
const residency = presetFor('preset:residency') as Preset;
const scfhs = presetFor('preset:scfhs') as Preset;

/** One ACGME row by the type key it carries. The bundle has one row per key, asserted below. */
const acgmeRow = (typeKey: string): Condition =>
    acgme.conditions.find((row) => row.typeKey === typeKey) as Condition;

describe('the preset registry', () => {
    /**
     * A guard over an empty array is green for the wrong reason, and a renamed export is exactly
     * how one gets there. Named keys rather than a count: a count survives the three presets being
     * replaced by three others.
     */
    it('ships the three bundles CG-08 names, and no others', () => {
        expect(PRESETS.map((preset) => preset.key)).toEqual(['preset:acgme', 'preset:residency', 'preset:scfhs']);
    });

    it('answers a lookup, and answers an unknown key with undefined rather than a guess', () => {
        expect(presetFor('preset:acgme')?.title).toBe(acgme.title);
        expect(presetFor('preset:nothing')).toBeUndefined();
    });

    it('gives every preset a title and a sentence describing it', () => {
        for (const preset of PRESETS) {
            expect(preset.title.length, `${preset.key} has no title`).toBeGreaterThan(0);
            expect(preset.describes.length, `${preset.key} describes nothing`).toBeGreaterThan(20);
        }
    });
});

describe('the manifest against the presets, in both directions', () => {
    it('declares every preset the registry ships', () => {
        const declared = PRESET_MANIFEST.map((entry) => entry.key).sort();

        expect(PRESETS.map((preset) => preset.key).filter((key) => !declared.includes(key))).toEqual([]);
    });

    it('declares no preset the registry does not ship', () => {
        const shipped = PRESETS.map((preset) => preset.key);

        expect(PRESET_MANIFEST.map((entry) => entry.key).filter((key) => !shipped.includes(key))).toEqual([]);
    });

    it('names each preset exactly once', () => {
        const keys = PRESET_MANIFEST.map((entry) => entry.key);

        expect(new Set(keys).size).toBe(keys.length);
    });

    /**
     * DIRECTION ONE: a preset naming a type the catalog does not have.
     *
     * `implemented` is part of it rather than a second case. A preset seeding a registered but
     * unimplemented row — `forbidden_transition` is the one — would throw
     * `UnimplementedConditionTypeError` the moment a department installed it, which on a gate screen
     * is a rule that appears to have been configured and cannot run.
     */
    it('covers only type keys the catalog implements', () => {
        for (const entry of PRESET_MANIFEST) {
            for (const typeKey of entry.covers) {
                const row = registryEntry(typeKey);

                expect(row, `${entry.key} covers "${typeKey}", which the catalog does not name`).toBeDefined();
                expect(row?.implemented, `${entry.key} covers "${typeKey}", which is registered unimplemented`).toBe(
                    true,
                );
            }
        }
    });

    /**
     * DIRECTION TWO: a catalog type silently dropped from a preset that claims to cover it.
     *
     * This is the direction a manifest is usually written without. Deleting a condition from
     * `acgme.ts` leaves every other check in this file green — the remaining four still validate,
     * still match the spec's figures, still round-trip as data — and only this one names the loss.
     */
    it('claims no type a preset does not actually carry', () => {
        for (const entry of PRESET_MANIFEST) {
            const preset = presetFor(entry.key) as Preset;
            const carried = typeKeysIn(preset);

            expect(
                entry.covers.filter((typeKey) => !carried.includes(typeKey)),
                `${entry.key} claims types it does not carry`,
            ).toEqual([]);
        }
    });

    /** DIRECTION THREE: a row added to a preset without being declared. */
    it('is not outrun by a preset that grew a row', () => {
        for (const entry of PRESET_MANIFEST) {
            const preset = presetFor(entry.key) as Preset;

            expect(
                typeKeysIn(preset).filter((typeKey) => !entry.covers.includes(typeKey)),
                `${entry.key} carries types the manifest does not declare`,
            ).toEqual([]);
        }
    });

    it('carries one entry per type key, in a stable order, so two runs read alike', () => {
        for (const entry of PRESET_MANIFEST) {
            expect(new Set(entry.covers).size, `${entry.key} declares a type twice`).toBe(entry.covers.length);
            expect(entry.covers, `${entry.key}'s covered list is unsorted`).toEqual([...entry.covers].sort());
        }
    });

    /**
     * The declared state against the state the CONTENTS produce.
     *
     * `state` is the field that makes *"present and empty"* a decision rather than a load failure
     * (Decision H). Deriving it from the contents is what stops it from becoming the thing a reader
     * trusts while the file underneath says something else: a bundle whose rows were all deleted
     * declares `ready` and derives `empty`, and the two disagreeing is the whole point.
     */
    it('declares the state each preset is actually in', () => {
        for (const entry of PRESET_MANIFEST) {
            const preset = presetFor(entry.key) as Preset;
            const derived =
                preset.conditions.length > 0 ? 'ready' : preset.drafts.length > 0 ? 'structure-only' : 'empty';

            expect(entry.state, `${entry.key} declares "${entry.state}" and contains "${derived}"`).toBe(derived);
        }
    });

    it('gives every entry a reason, because an entry is a decision and not documentation', () => {
        for (const entry of PRESET_MANIFEST) {
            expect(entry.reason.length, `${entry.key} states no reason`).toBeGreaterThan(40);
        }
    });

    /**
     * A preset that is not `ready` MUST carry `pending`, and one that is ready must not.
     *
     * Decision H makes the `pending` block mandatory on SCFHS precisely because *"an empty array is
     * indistinguishable from a failed load and from nobody having written it yet"*. The same
     * sentence applies to a structure whose numbers are awaited, so the rule is derived from the
     * state rather than named per preset.
     */
    it('pairs an unfinished preset with the block that says what it is waiting for', () => {
        for (const entry of PRESET_MANIFEST) {
            const preset = presetFor(entry.key) as Preset;

            if (entry.state === 'ready') {
                expect(preset.pending, `${entry.key} is ready and still declares itself pending`).toBeUndefined();

                continue;
            }

            expect(preset.pending, `${entry.key} is ${entry.state} and says nothing about what it awaits`).toBeDefined();
            expect(preset.pending?.awaits.length ?? 0).toBeGreaterThan(20);
            expect(preset.pending?.from.length ?? 0).toBeGreaterThan(0);
            expect(() => parseYmd(preset.pending?.lastCheckedOn ?? '')).not.toThrow();
        }
    });
});

describe('a preset is configuration, not code', () => {
    /**
     * The runtime half, and it is stronger than any needle: a value graph that survives
     * `JSON.parse(JSON.stringify(...))` unchanged holds no function, no `undefined`, no class
     * instance and no date object. Decision H's *"what a preset can physically be is a JSON data
     * file"* is asserted as a property of the value rather than as a file extension.
     *
     * THE EXTENSION IS THE ONE DEVIATION FROM DECISION H AND IT IS THE TREE'S OWN PRECEDENT:
     * `contract/schema.ts` is a JSON Schema document living in a `.ts` file because a JSON import
     * *"would resolve differently under the bundler, under plain Node and under `tsc`, which is
     * three answers to a question worth none"*. A preset is imported by exactly the same three
     * consumers. It is a JSON data file by value, which is what this case measures.
     */
    it('holds nothing a JSON round trip would lose', () => {
        expect(JSON.parse(JSON.stringify(PRESETS))).toEqual(PRESETS);
        expect(JSON.parse(JSON.stringify(PRESET_MANIFEST))).toEqual(PRESET_MANIFEST);
    });

    /**
     * The source half, because the runtime half cannot see a predicate that ran at module load and
     * left a number behind. A preset names type keys and supplies parameters; a preset that
     * imported the thing it configures would be the first step toward a bundle that decides
     * something, and the decision belongs to the department and its gate screen.
     */
    it('imports nothing but a type, and defines no function', () => {
        for (const name of ['acgme.ts', 'residency.ts', 'scfhs.ts', 'manifest.ts']) {
            const source = withoutComments(readFileSync(join(PRESET_DIR, name), 'utf8'));
            const imports = source.match(/^import .*/gm) ?? [];

            expect(imports.length, `${name} imports nothing at all — has it stopped being typed?`).toBeGreaterThan(0);

            for (const line of imports) {
                expect(line.startsWith('import type '), `${name} imports a value: ${line}`).toBe(true);
            }

            expect(source, `${name} defines an arrow function`).not.toMatch(/=>/);
            expect(source, `${name} defines a function`).not.toMatch(/\bfunction\b/);
        }
    });
});

describe('the ACGME bundle', () => {
    const CG_08 = (() => {
        const spec = readFileSync(SPEC_PATH, 'utf8');
        const start = spec.indexOf('CG-08');
        const end = spec.indexOf('CG-09', start);

        if (start < 0 || end < 0) {
            throw new Error('CG-08 was not found in SPEC.md; this guard reads its numbers out of that sentence.');
        }

        return spec.slice(start, end);
    })();

    /** One capture group out of CG-08's own sentence, as a number. Throws rather than defaulting. */
    const figure = (pattern: RegExp, index = 1): number => {
        const found = pattern.exec(CG_08);

        if (found === null) {
            throw new Error(`CG-08's sentence no longer matches ${pattern}; the preset's numbers came from it.`);
        }

        return Number(found[index]);
    };

    it('found CG-08, and it still names the bundle this preset encodes', () => {
        expect(CG_08).toContain('Duty-hours (ACGME-style)');
        expect(CG_08.length).toBeGreaterThan(100);
    });

    it('is five soft rows, active, at one rank, sourced to itself', () => {
        expect(acgme.conditions).toHaveLength(5);

        for (const row of acgme.conditions) {
            expect(validate('Condition', row), `${row.id} is not a contract-shaped condition`).toEqual([]);
            expect(row.class, `${row.id} is not soft`).toBe('soft');
            expect(row.active, `${row.id} installs switched off`).toBe(true);
            expect(row.rank, `${row.id} does not sit at the top`).toBe(1);
            expect(row.source, `${row.id} does not name the preset it came from`).toBe('preset:acgme');
            expect(row.scope, `${row.id} narrows a population the department did not choose`).toBeUndefined();
        }

        expect(new Set(acgme.conditions.map((row) => row.id)).size).toBe(5);
    });

    /**
     * Every parameterisation against the type's REAL schema, out of the registry.
     *
     * Not against the plan's prose and not against a copy: several schemas changed during
     * implementation — `free_day_min` requires only `n`, `call_frequency_max` requires `windowDays`
     * rather than a `weeks` count — and a preset written from CG-08's sentence alone would name
     * parameters no type reads.
     */
    it('parameterises every row against the schema its own type publishes', () => {
        for (const row of acgme.conditions) {
            const schema = registryEntry(row.typeKey)?.paramsSchema;

            expect(schema, `${row.typeKey} publishes no params schema`).toBeDefined();
            expect(validateAgainst(schema!, row.params), `${row.id}'s params are refused by ${row.typeKey}`).toEqual(
                [],
            );
        }
    });

    it("takes its 80 hours a week over four weeks from CG-08's own sentence", () => {
        const hours = figure(/(\d+) h\/week averaged over (\d+) weeks/);
        const weeks = figure(/(\d+) h\/week averaged over (\d+) weeks/, 2);
        const row = acgmeRow('rolling_hours_max');

        expect(row.params).toEqual({ hours, windowDays: 7, averagingWeeks: weeks });

        // The averaging is the parameter nobody reads correctly, so the multiplied-out figure is
        // asserted rather than the two halves: 80 a week over four weeks is 320 in any 28 days,
        // and NOT four weekly caps of 80. Both readings write the same three parameters.
        expect(effectiveCap({ hours, windowDays: 7, averagingWeeks: weeks })).toEqual({ hours: 320, windowDays: 28 });
    });

    it('takes its one-in-three call density from the same sentence, and measures it in days', () => {
        const n = figure(/call ≤ 1-in-(\d+) averaged/);
        const row = acgmeRow('call_frequency_max');

        // "Averaged" carries no number of its own in CG-08; the four weeks are the first clause's,
        // and the window is a count of DAYS rather than weeks so a department changing its weekend
        // cannot silently move a duty-hours rule.
        expect(row.params).toEqual({ n, windowDays: 7 * figure(/(\d+) h\/week averaged over (\d+) weeks/, 2) });
        expect(row.params.windowDays).toBe(28);
    });

    it('takes its one-free-day-in-seven from the same sentence, and multiplies both halves', () => {
        const n = figure(/1-in-(\d+) free averaged/);
        const weeks = figure(/(\d+) h\/week averaged over (\d+) weeks/, 2);
        const row = acgmeRow('free_day_min');

        expect(row.params).toEqual({ n, averagingWeeks: weeks });

        // `leaveCountsAsFree` is the type's own default and is not a parameter of the rule being
        // measured here; it is supplied because `effectiveRule` takes normalised params.
        expect(effectiveRule({ n, averagingWeeks: weeks, leaveCountsAsFree: true })).toEqual({
            windowDays: 28,
            freeDays: 4,
        });
    });

    it('takes its ten hours between duties from the same sentence, end to start', () => {
        const value = figure(/(\d+) h between duties/);

        expect(acgmeRow('min_gap').params).toEqual({ value, unit: 'hours' });
    });

    it('takes its twenty-four-hour continuous cap from the same sentence', () => {
        const count = figure(/(\d+) h continuous cap/);
        const row = acgmeRow('consecutive_max');

        expect(row.params).toEqual({ count, unit: 'hours', transitionMinutes: 240 });
    });

    /**
     * `transitionMinutes` is the ONE figure in this bundle that no document in this repository
     * states, and the preset says so on itself rather than only here.
     *
     * CG-08 drops the clause entirely; Appendix A names it in words — *"with limited transition
     * time"* — and gives no number. So the case asserts the ABSENCE at the source as well as the
     * presence of the limitation, because a figure appearing in CG-08 later would make the
     * limitation false and nothing else here would notice.
     */
    it('states the one number the repository does not source, and the sentence still does not', () => {
        expect(CG_08).not.toMatch(/transition/i);
        expect(acgme.limitations.filter((clause) => /transition/i.test(clause))).toHaveLength(1);
    });

    it('states the clauses it cannot implement, and says which are which', () => {
        expect(acgme.limitations.length).toBeGreaterThanOrEqual(3);
        expect(acgme.limitations.some((clause) => /home call/i.test(clause))).toBe(true);
        expect(acgme.limitations.some((clause) => /floor, not an audited total/i.test(clause))).toBe(true);
    });
});

describe('the residency bundle', () => {
    it('installs nothing at all — it is structure, and the numbers are the owner\'s', () => {
        expect(residency.conditions).toEqual([]);
        expect(residency.drafts.length).toBeGreaterThan(0);
    });

    /**
     * The awaited list is DERIVED from the type's own schema, both directions in one equality.
     *
     * A hand-written list is a second copy of `required` that agrees with it until a schema gains a
     * parameter — and then it reads as complete while a department fills in a form that is missing
     * a field. `vacation_block` and `unwanted_day_block` publish an EMPTY schema and therefore await
     * nothing; they are in the bundle because Appendix A names them, and they still do not install,
     * because a preset a department installs half of is a preset that looks finished on a gate
     * screen.
     */
    it('awaits exactly the parameters each type declares required', () => {
        for (const draft of residency.drafts) {
            const schema = registryEntry(draft.typeKey)?.paramsSchema;

            expect(schema, `${draft.typeKey} publishes no params schema`).toBeDefined();
            expect(draft.awaiting, `${draft.typeKey}'s awaited list is not its schema's required list`).toEqual([
                ...(schema?.required ?? []),
            ]);
        }
    });

    it('cites, per type, what puts it in a residency bundle at all', () => {
        for (const draft of residency.drafts) {
            expect(draft.because.length, `${draft.typeKey} is in the bundle for no stated reason`).toBeGreaterThan(30);
        }

        expect(residency.drafts.map((draft) => draft.typeKey)).toContain('onboarding_grace');
    });

    it('names no type twice', () => {
        const keys = residency.drafts.map((draft) => draft.typeKey);

        expect(new Set(keys).size).toBe(keys.length);
    });
});

describe('the SCFHS bundle', () => {
    it('is present and empty, which is a different statement from absent', () => {
        expect(scfhs.conditions).toEqual([]);
        expect(scfhs.drafts).toEqual([]);
        expect(scfhs.limitations).toEqual([]);
    });

    it('says what it awaits, who supplies it, and when somebody last asked', () => {
        expect(scfhs.pending?.awaits).toMatch(/numeric/i);
        expect(scfhs.pending?.from.length ?? 0).toBeGreaterThan(0);
        expect(() => parseYmd(scfhs.pending?.lastCheckedOn ?? '')).not.toThrow();
    });
});

/**
 * The bundle, run.
 *
 * Decision H's own argument for `active: true` is that *"a preset that installs inert is another
 * control that appears to do something"*, and a preset whose parameters merely VALIDATE is inert in
 * a way no schema check can see: `windowDays: 7` with no averaging validates, and asks for a
 * quarter of what CG-08 says. So the five rows are evaluated against two worlds — one that breaches
 * every clause and one that breaches none — and the pair is what makes the first non-vacuous.
 *
 * The world is generated rather than written into the corpus on purpose: it is a property of the
 * PRESET, not a case about a type, and it is 35 days long because the bundle's own windows are 28.
 * Synthetic, permanently, like everything else under `test/`.
 */
describe('the ACGME bundle, evaluated', () => {
    const FROM = parseYmd('2026-08-01');
    const TO = parseYmd('2026-09-04');
    const EVALUABLE_FROM = parseYmd('2026-07-01');
    const EVALUABLE_TO = parseYmd('2026-10-01');
    const WEEKEND = [5, 6];

    const days: Day[] = datesBetween(EVALUABLE_FROM, EVALUABLE_TO).map((date) => ({
        date,
        isoWeekday: isoWeekday(date),
        dayType: WEEKEND.includes(isoWeekday(date)) ? 'WE' : 'WD',
        periodKey: null,
        holidays: [],
    }));

    const horizonDates = datesBetween(FROM, TO);

    /**
     * `eligibleDays` spans the EVALUABLE range, not the horizon, and that is not a detail.
     *
     * Owner decision J makes `call_frequency_max`'s allowance `floor(availableDays / n)` over the
     * window's own contents, and its 28-day windows reach a month back past `horizon.from`. Handed
     * eligible days for the horizon alone, the rule reads those earlier windows as *"this person
     * was available for one day of the 28"*, allows zero calls in them, and fires on a schedule
     * that breaches nothing. Found here, on the quiet world — which is exactly what the quiet world
     * is for, and is a note `App\Support\Engine`'s callers need: the availability vector is a fact
     * about the EVALUABLE range.
     */
    const person: Person = {
        key: 'p-oncall',
        levelSpans: [{ key: 'R2', from: EVALUABLE_FROM, to: EVALUABLE_TO }],
        unitSpans: [{ key: 'PICU', from: EVALUABLE_FROM, to: EVALUABLE_TO }],
        leaveDays: [],
        unwantedDays: [],
        eligibleDays: datesBetween(EVALUABLE_FROM, EVALUABLE_TO),
        external: false,
    };

    const context: EvaluationContext = {
        timezone: 'Asia/Riyadh',
        weekStartIsoDay: 7,
        weekendDays: WEEKEND,
        today: FROM,
        days,
        periods: [],
        people: [person],
        slots: [
            {
                key: 'full24',
                kind: 'call',
                unitKey: 'PICU',
                cadence: 'daily',
                spanDays: 1,
                startMinute: 480,
                endMinute: 480,
                crossesMidnight: true,
                countsHours: true,
                tallyKey: 'calls',
            },
        ],
        clinics: [],
        historyAvailableFrom: EVALUABLE_FROM,
        priorDuties: [],
        followingDuties: [],
    };

    const scheduleOf = (dates: Ymd[]): Schedule => ({
        horizon: { from: FROM, to: TO, evaluableFrom: EVALUABLE_FROM, evaluableTo: EVALUABLE_TO },
        duties: dates.map((date) => ({ personKey: person.key, date, slotKey: 'full24' })),
    });

    it('was handed a world the contract recognises', () => {
        expect(validate('EvaluationContext', context)).toEqual([]);
        expect(validate('Schedule', scheduleOf(horizonDates))).toEqual([]);
    });

    it('fires every one of its five rules on somebody who is never off', () => {
        const violations = evaluate(scheduleOf(horizonDates), context, acgme.conditions);
        const fired = [...new Set(violations.map((violation) => violation.conditionId))].sort();

        expect(fired).toEqual(acgme.conditions.map((row) => row.id).sort());
    });

    it('stays quiet on a month it permits, so the case above is not firing on anything', () => {
        const light = horizonDates.filter((_, index) => index % 10 === 0);

        expect(evaluate(scheduleOf(light), context, acgme.conditions)).toEqual([]);
    });
});
