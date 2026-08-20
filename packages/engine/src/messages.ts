/**
 * CG-04's message table: every sentence a preview can produce, in one place, in one language.
 *
 * ## Why a table and not string literals in twenty-two predicates
 *
 * AR-07 makes translation future work rather than no work. A sentence assembled from literals
 * inside a condition module is a sentence that can only ever be English, and the cost of finding
 * that out is discovering it twenty-two times. So the sentences live here, the type modules pass
 * already-normalised VALUES in, and {@link PreviewMessages} is the interface a second language
 * would implement. `preview.test.ts` asserts that by handing the dispatcher a second table and
 * watching the output change — a message table nothing can override is a message table in name
 * only.
 *
 * The arguments are structural rather than imported from the type modules, deliberately: this file
 * must not import anything that imports it back, and a message table that knows a predicate's
 * internal shape is a table that changes whenever the predicate does.
 *
 * ## Numbers arrive already decided
 *
 * No arithmetic happens in this file. `fairness_distribution`'s worked allowances and
 * `rolling_hours_max`'s multiplication are computed by the types that own those rules and handed
 * in — because a number computed in the sentence and a number computed in the predicate are two
 * definitions of one fact, which is the failure `AuditChain::canonical()` already carries a
 * docblock against. This file decides only how a number is SAID.
 *
 * ## The four sentences here are the four whose wording is a decision
 *
 * Each of them renders a number a reader would otherwise predict wrongly, and each was settled by
 * an owner decision rather than by taste: the `min_gap` off-by-one (H), the averaging
 * multiplication (H/V), the proportional tolerance with its floor (Q, answered 2026-08-20), and
 * the replace-not-adjust modifier (M, answered 2026-08-20). The remaining eighteen land with their
 * predicates, and `preview.test.ts`'s coupling check refuses an evaluator that arrives without one.
 */

/** One base target, already paired with the level it belongs to. */
export interface LevelTarget {
    levelKey: string;
    target: number;
}

/** One modifier, with its condition already rendered as a clause and its replacement target. */
export interface TargetModifier {
    clause: string;
    target: number;
}

/** One worked point on a tolerance curve: what an expected share of `share` actually allows. */
export interface ToleranceExample {
    share: number;
    allowance: number;
}

/** Every sentence CG-04 can produce, and the shared vocabulary they are built from. */
export interface PreviewMessages {
    /** `a`, `a and b`, `a, b and c`. Empty gives the empty string; callers decide what that means. */
    conjoin(items: readonly string[]): string;

    /** `1 duty` / `3 duties`. The count is always rendered; only the noun changes. */
    plural(count: number, one: string, many: string): string;

    /** Owner decision H: `days` measures between START DATES and `N` means at least N apart. */
    minGap(args: { value: number; unit: 'days' | 'hours'; kinds: readonly string[] }): string;

    /** The cap at one scale, and — when it is averaged — the same cap multiplied out at the other. */
    rollingHoursMax(args: {
        hours: number;
        windowDays: number;
        averagingWeeks: number | null;
        averagedHours: number | null;
        averagedDays: number | null;
    }): string;

    /** Owner decision Q: the allowance is stated as a NUMBER at both regimes, never as a percentage. */
    fairnessDistribution(args: {
        quantity: string;
        mode: 'deviation' | 'spread';
        excludeExternal: boolean;
        examples: readonly ToleranceExample[];
    }): string;

    /** Owner decision M: an exception REPLACES the target, and every branch prints its own number. */
    targetPerPeriod(args: { targets: readonly LevelTarget[]; modifiers: readonly TargetModifier[] }): string;

    /** A modifier's `vacationWeeksAtLeast` predicate, as a clause. */
    vacationWeeksAtLeast(weeks: number): string;

    /** A modifier's `periodWeeksAtMost` predicate, as a clause. */
    periodWeeksAtMost(weeks: number): string;
}

/**
 * The English table.
 *
 * The wording is plain on purpose: CG-01 shows this text on the gate screen next to a drag handle,
 * to a scheduler who has not read the spec and never will. Every sentence says what happens, with
 * the department's own numbers in it, and no sentence asks the reader to do arithmetic to find out
 * what the rule permits.
 */
export const EN: PreviewMessages = {
    conjoin(items) {
        if (items.length <= 1) {
            return items[0] ?? '';
        }

        return `${items.slice(0, -1).join(', ')} and ${items[items.length - 1] as string}`;
    },

    plural(count, one, many) {
        return `${count} ${count === 1 ? one : many}`;
    },

    minGap({ value, unit, kinds }) {
        const between = kinds.length === 0 ? 'duties' : `duties of kind ${EN.conjoin(kinds)}`;

        if (unit === 'hours') {
            return (
                `At least ${value} h between the end of one duty and the start of the next, ` +
                `counting ${between}.`
            );
        }

        return (
            `At least ${EN.plural(value, 'day', 'days')} between ${between}, counted between the dates ` +
            `they start on — 1 Aug then ${1 + value} Aug is allowed, 1 Aug then ${value} Aug is not.`
        );
    },

    rollingHoursMax({ hours, windowDays, averagingWeeks, averagedHours, averagedDays }) {
        const base = `At most ${hours} h of duty in any ${windowDays} consecutive days`;

        if (averagingWeeks === null || averagedHours === null || averagedDays === null) {
            return `${base}.`;
        }

        return (
            `${base}, averaged over ${averagingWeeks} such windows — at most ${averagedHours} h in any ` +
            `${averagedDays} consecutive days.`
        );
    },

    fairnessDistribution({ quantity, mode, excludeExternal, examples }) {
        const worked = EN.conjoin(
            examples.map(({ share, allowance }) => `an expected share of ${share} allows ${allowance}`),
        );

        const compared =
            mode === 'deviation'
                ? "each person's count is compared with their own expected share, pro-rated by the days " +
                  'they are actually available'
                : 'the gap between the busiest and the quietest person is measured against the same ' +
                  'expected share, pro-rated by the days each is actually available';

        const external = excludeExternal
            ? 'External people are excluded from the comparison.'
            : 'External people are counted in the comparison.';

        return (
            `Everyone's share of ${quantity} is spread evenly: ${compared}. The allowance is one duty ` +
            `up to an expected share of ten, and a tenth of the expected share above that — ${worked}. ` +
            external
        );
    },

    targetPerPeriod({ targets, modifiers }) {
        const base = `Each period: ${EN.conjoin(
            targets.map(({ levelKey, target }) => `${levelKey} ${EN.plural(target, 'duty', 'duties')}`),
        )}.`;

        if (modifiers.length === 0) {
            return base;
        }

        const exceptions = modifiers
            .map(({ clause, target }) => `where ${clause}, ${EN.plural(target, 'duty', 'duties')} instead`)
            .join('; ');

        const replaces = modifiers.length === 1 ? 'One exception replaces' : 'Exceptions replace';

        return `${base} ${replaces} that number outright rather than adjusting it, first match wins: ${exceptions}.`;
    },

    vacationWeeksAtLeast(weeks) {
        return `a person has at least ${EN.plural(weeks, 'vacation week', 'vacation weeks')} in the period`;
    },

    periodWeeksAtMost(weeks) {
        return `the period is at most ${EN.plural(weeks, 'week', 'weeks')} long`;
    },
};
