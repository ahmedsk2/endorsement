import { readFileSync, readdirSync } from 'node:fs';
import { join } from 'node:path';

import { describe, expect, it } from 'vitest';

import type { Condition, Fixture, Messages, Violation } from '../src/contract/types';
import { coverage } from '../src/coverage';
import { evaluate } from '../src/evaluate';
import { EN } from '../src/messages';

/**
 * The message table's VIOLATION half (P2-2 Task 1), and the proof that it is reached.
 *
 * ## What this file exists to prevent
 *
 * `ConditionPreview` took the table as an argument from Task 9; `ConditionEvaluator` was fixed at
 * Task 7 without one, so every type assembled its violation English at the call site. AR-07 —
 * *"strings are externalized from launch so a future locale is translation work, not a rewrite"* —
 * held for weekday names and Hijri months and did not hold here. Eleven types had hardcoded a
 * sentence; eleven more arrive in P2-2, and the shape would then have been set by whichever was
 * written first.
 *
 * ## The proof is a SECOND TABLE, and it covers every migrated type rather than one
 *
 * `preview.test.ts` established the device at Task 9: hand the dispatcher a second table and watch
 * the sentence change, because a message table nothing can override is a message table in name
 * only. It proved it on `min_gap` alone, which is one type of fourteen.
 *
 * Here the second table is built from `EN`'s OWN KEYS — every method replaced by one returning its
 * own name in guillemets — so a method added tomorrow is tagged automatically and cannot escape the
 * check by being forgotten. Four plants stayed green in P2-1 by being asserted at an input where
 * the defect could not appear; a threading that silently fell back to a literal for one type of
 * eleven would be the fifth, and a hand-written second table listing ten of eleven methods is
 * exactly how that happens.
 *
 * The corpus is the input because it is the only place in this package where each of the eleven
 * types actually FIRES. A world invented here would be a world invented to make the assertion pass.
 *
 * ## What is asserted elsewhere, deliberately
 *
 *  - **That the English did not move.** `conditions.test.ts` compares every corpus case against its
 *    expected `explanation` verbatim, so a migration that reworded a sentence while relocating it
 *    fails there. This file asserts the sentence comes from the table; that file asserts WHICH
 *    sentence, byte for byte. Neither is the other.
 *  - **That no condition module builds an explanation from a literal at all.** A behavioural check
 *    reaches only the sentence shapes the corpus produces, and two types carry a second shape the
 *    corpus does not reach on every case. `conditions.test.ts`'s source scan covers the rest.
 *  - **The preview half.** `preview.test.ts` owns it, over every previewable entry, using the probe
 *    generator that already lives there.
 */

const FIXTURE_DIR = join(import.meta.dirname, 'fixtures', 'conditions');

function loadFixtures(): Fixture[] {
    return readdirSync(FIXTURE_DIR)
        .filter((name) => name.endsWith('.json'))
        .sort()
        .map((name) => JSON.parse(readFileSync(join(FIXTURE_DIR, name), 'utf8')) as Fixture);
}

const FIXTURES = loadFixtures();

/**
 * A second table, derived from the first rather than written out beside it.
 *
 * Every method of `EN` — the shared vocabulary, the preview sentences and the violation sentences
 * alike — is replaced by one returning its own name. Derived, because a hand-written second table
 * is a list somebody keeps up to date, and the failure it would hide is precisely the one this file
 * exists to catch: a sentence that never reached the table at all.
 *
 * Tagging `conjoin`, `plural` and `hours` matters as much as tagging the sentences. A type that
 * called the vocabulary directly and wrapped a literal around the result would still be assembling
 * English at the call site, and its output would be a tag WITH text around it rather than a tag.
 */
const SHOUTING = Object.fromEntries(
    Object.keys(EN).map((key) => [key, () => `«${key}»`]),
) as unknown as Messages;

/** Every type key in the corpus, mapped to the distinct sentences its violations came from. */
function sentencesByTypeKey(messages: Messages): Record<string, string[]> {
    const found: Record<string, Set<string>> = {};

    for (const fixture of FIXTURES) {
        const typeKeyById = new Map(fixture.conditions.map((row: Condition) => [row.id, row.typeKey]));

        for (const violation of evaluate(fixture.schedule, fixture.context, fixture.conditions, messages)) {
            const typeKey = typeKeyById.get(violation.conditionId) as string;

            (found[typeKey] ??= new Set()).add(violation.explanation);
        }
    }

    return Object.fromEntries(
        Object.entries(found)
            .sort(([a], [b]) => (a < b ? -1 : 1))
            .map(([typeKey, sentences]) => [typeKey, [...sentences].sort()]),
    );
}

/** Every distinct coverage reason the corpus produces, from whichever table is handed in. */
function coverageReasons(messages: Messages): string[] {
    const reasons = new Set<string>();

    for (const fixture of FIXTURES) {
        for (const report of coverage(fixture.schedule, fixture.context, fixture.conditions, messages)) {
            for (const skipped of report.skipped) {
                reasons.add(skipped.reason);
            }
        }
    }

    return [...reasons].sort();
}

function violationCount(messages: Messages): number {
    return FIXTURES.reduce(
        (total: number, fixture: Fixture) =>
            total + evaluate(fixture.schedule, fixture.context, fixture.conditions, messages).length,
        0,
    );
}

describe('the violation half of the message table', () => {
    /**
     * The whole point of the task, asserted over ELEVEN types and fourteen sentence shapes rather
     * than over one. A type still assembling its own English shows up as English here, next to ten
     * neighbours showing up as tags — which is a diff a reader can act on, unlike a single failed
     * `toBe`.
     */
    it('renders every explanation the corpus produces through the table it is handed', () => {
        expect(sentencesByTypeKey(SHOUTING)).toEqual({
            clinic_conflict: ['«clinicConflictViolation»'],
            consecutive_max: ['«consecutiveMaxDatesViolation»', '«consecutiveMaxHoursViolation»'],
            dow_restriction: ['«dowRestrictionViolation»'],
            eligibility: ['«eligibilityViolation»'],
            min_gap: ['«minGapViolation»'],
            onboarding_grace: [
                '«onboardingGraceBeforeJoinViolation»',
                '«onboardingGraceViolation»',
            ],
            overlap_block: ['«overlapBlockViolation»'],
            post_duty_exclusion: ['«postDutyExclusionViolation»'],
            same_unit_conflict: ['«sameUnitConflictViolation»'],
            unwanted_day_block: ['«unwantedDayBlockViolation»'],
            vacation_block: ['«vacationBlockViolation»'],
        });
    });

    /**
     * `coverage()` is the other half of the same contract change and would otherwise have been the
     * residual this task was written to remove. A skipped window is text a scheduler reads on the
     * gate screen beside the violations — *"1 evaluated, 1 skipped, and here is why"* — and a
     * sentence that is English wherever it is assembled is not externalized because its neighbour
     * is.
     */
    it('renders every coverage reason the corpus produces through the same table', () => {
        expect(coverageReasons(SHOUTING)).toEqual(['«carryInSkip»', '«unknownJoinDateSkip»']);
    });

    /**
     * The third coverage reason, which no corpus case produces because no corpus case switches a
     * condition off. It is the one CG-01 makes a scheduler reachable with one click, and the reason
     * `evaluate()` refuses to treat an inactive condition as silence.
     */
    it('renders the inactive-condition reason through the table too', () => {
        const inactive: Condition = {
            id: 'c-off',
            typeKey: 'vacation_block',
            class: 'hard',
            active: false,
            params: {},
        };
        const fixture = FIXTURES.find((row: Fixture) => row.name.startsWith('vacation-block')) as Fixture;

        expect(
            coverage(fixture.schedule, fixture.context, [inactive], SHOUTING)[0]?.skipped.map(
                (skipped) => skipped.reason,
            ),
        ).toEqual(['«inactiveConditionSkip»']);
    });

    /**
     * The non-vacuity floor under all three checks above.
     *
     * A corpus directory that has been moved or emptied iterates nothing, produces no violation and
     * no reason, and `toEqual({})` against an expectation nobody re-read would look exactly like a
     * clean tree. This is `preview.test.ts`'s recorded reason for preferring a floor to a staleness
     * twin, and it applies harder here because the input is loaded from disk.
     *
     * It also pins the OTHER direction: under `EN` the same corpus produces no tag at all, so the
     * two tables genuinely differ and the checks above are not comparing a thing with itself.
     */
    it('found violations and reasons to render, and English under EN rather than tags', () => {
        expect(FIXTURES.length).toBeGreaterThanOrEqual(34);
        expect(violationCount(SHOUTING)).toBeGreaterThanOrEqual(30);
        expect(violationCount(EN)).toBe(violationCount(SHOUTING));
        expect(Object.keys(sentencesByTypeKey(EN))).toHaveLength(11);

        const underEn = [...Object.values(sentencesByTypeKey(EN)).flat(), ...coverageReasons(EN)];

        expect(underEn.filter((sentence) => sentence.includes('«'))).toEqual([]);
        expect(underEn.length).toBeGreaterThanOrEqual(14);
    });

    /**
     * Owner decision Q's residual, and the reason this task was chartered BEFORE P2-2's types
     * rather than after them.
     *
     * Task 9 settled `fairness_distribution`'s PREVIEW wording as two worked points, because a
     * preview has the department's parameters and not its schedule. The number actually applied is
     * knowable only where the schedule is, which is inside the predicate — so it belongs in the
     * violation, and Task 19 owes it there. What this task had to do was make it REACHABLE: the
     * table's violation half receives numbers that are already decided, computed by the predicate
     * on the same call that renders them.
     *
     * `minGapViolation` is the demonstration and is asserted as such: the shortfall it prints is
     * measured by the predicate against that person's own duties and handed in, and no arithmetic
     * happens in the table beyond saying it. A sentence that took `value` alone could not print an
     * applied number at all, and that is the shape Task 19 would have inherited.
     */
    it('carries an applied number the predicate measured, not just the authored parameter', () => {
        const sentences = new Set<string>();

        for (const fixture of FIXTURES) {
            for (const violation of evaluate(
                fixture.schedule,
                fixture.context,
                fixture.conditions.filter((row: Condition) => row.typeKey === 'min_gap'),
                EN,
            ) as Violation[]) {
                sentences.add(violation.explanation);
            }
        }

        const applied = [...sentences].filter((sentence) => /^Only [0-9.]+ h between/.test(sentence));

        expect(applied.length).toBeGreaterThanOrEqual(1);
        expect(applied.some((sentence) => sentence.includes('at least'))).toBe(true);
    });
});
