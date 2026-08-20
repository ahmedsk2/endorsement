/**
 * The message table: every sentence this engine can produce, in one place, in one language.
 *
 * ## Why a table and not string literals in twenty-two predicates
 *
 * AR-07 makes translation future work rather than no work. A sentence assembled from literals
 * inside a condition module is a sentence that can only ever be English, and the cost of finding
 * that out is discovering it twenty-two times. So the sentences live here, the type modules pass
 * already-normalised VALUES in, and {@link Messages} is the interface a second language would
 * implement. `preview.test.ts` and `messages.test.ts` assert that by handing the dispatchers a
 * second table and watching the output change — a message table nothing can override is a message
 * table in name only.
 *
 * The arguments are structural rather than imported from the type modules, deliberately: this file
 * must not import anything that imports it back, and a message table that knows a predicate's
 * internal shape is a table that changes whenever the predicate does. That is why a date arrives
 * here as a plain `string` and not as the branded `Ymd` the rest of the package uses.
 *
 * ## Three interfaces, because a preview and a violation are shown in different places
 *
 * {@link Vocabulary} is what both halves share. {@link PreviewMessages} is CG-04's gate-screen
 * description of a RULE, rendered before any draft exists. {@link ViolationMessages} is what a
 * scheduler reads on a badged cell, plus the `coverage()` rows saying what a condition could not
 * measure. `ConditionPreview` takes the first and `ConditionEvaluator` the second, so neither can
 * reach for the other's sentences and a P2-2 type adding a violation sentence does not widen the
 * type every preview is written against. `EN` implements {@link Messages}, which is both.
 *
 * ## The violation half arrived one task late, and the ordering is the whole reason it arrived
 *
 * `ConditionPreview` took the table as an argument from P2 Task 9. `ConditionEvaluator` was fixed at
 * Task 7 without one, so eleven types hardcoded their violation English at the call site and AR-07
 * held for weekday names and Hijri months but not for the sentence beside them. Threading it is a
 * contract change to `evaluate()`/`coverage()`: done as P2-2's first task it touched eleven call
 * sites, and done after P2-2 it would have touched twenty-two, with the shape set by whichever type
 * happened to be written first.
 *
 * The argument for waiting — that P2-2's types are where one learns what a good explanation reads
 * like — was considered and rejected, and this file is why it is safe to reject: the table is a
 * LOOKUP, a key and an interpolation map. Learning better wording later changes the values here and
 * not the shape.
 *
 * ## Numbers arrive already decided
 *
 * No arithmetic happens in this file. `fairness_distribution`'s worked allowances,
 * `rolling_hours_max`'s multiplication, `min_gap`'s measured shortfall and `consecutive_max`'s
 * stretch length are computed by the types that own those rules and handed in — because a number
 * computed in the sentence and a number computed in the predicate are two definitions of one fact,
 * which is the failure `AuditChain::canonical()` already carries a docblock against. This file
 * decides only how a number is SAID.
 *
 * **That is what makes owner decision Q reachable at Task 19.** A tolerance is proportional, so the
 * sentence must print the number ACTUALLY APPLIED and never `10%`. A preview cannot: it has the
 * department's parameters and not its schedule. A violation can, because the predicate that
 * measures the applied allowance is handed the table on the same call — {@link
 * ViolationMessages.minGapViolation}'s `apart` is the demonstration, measured against that person's
 * own duties rather than read off the condition row. Owner decision M's effective target reaches
 * {@link PreviewMessages.targetPerPeriod} the same way.
 *
 * ## The sentences whose wording is a decision rather than taste
 *
 * Each renders a number a reader would otherwise predict wrongly, and each was settled by an owner
 * decision: the `min_gap` off-by-one (H), the averaging multiplication (H/V), the proportional
 * tolerance with its floor (Q), and the replace-not-adjust modifier (M). `preview.test.ts` asserts
 * those four verbatim; `conditions.test.ts` asserts every violation sentence verbatim through the
 * corpus, which is what stops a relocation from quietly becoming a rewording.
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

/** One slot's allowance, already normalised — absent lists arrive as empty ones. */
export interface SlotAllowanceText {
    slotKey: string;
    levelKeys: readonly string[];
    unitKeys: readonly string[];
}

/**
 * One duty named INSIDE a sentence about another one — the other half of an overlapping pair, the
 * anchor a post-duty window opened from, the partner a gap was measured to.
 *
 * A duty is identified to a reader by its slot and its date and by nothing else: `personKey` is
 * absent because every sentence carrying one of these is already located at that person's placement,
 * and repeating it would put a name in a badge that is already under their row.
 */
export interface DutyRef {
    slotKey: string;
    date: string;
}

/** What both halves of the table are built from. A second language reimplements these too. */
export interface Vocabulary {
    /** `a`, `a and b`, `a, b and c`. Empty gives the empty string; callers decide what that means. */
    conjoin(items: readonly string[]): string;

    /** `1 duty` / `3 duties`. The count is always rendered; only the noun changes. */
    plural(count: number, one: string, many: string): string;

    /**
     * Minutes as a count of hours: `4`, `26`, `10.5`. The unit itself belongs to the sentence.
     *
     * ONE definition, and it belongs here rather than beside the arithmetic: `min_gap` and
     * `consecutive_max` both render a duration and two formatters would eventually disagree about
     * the same number on two badges of the same cell — and the decimal SEPARATOR is a locale's
     * decision, which is the half a formatter living outside the table could never honour. Whole
     * hours print whole: a scheduler reading `4.0 h` wonders what the missing precision was.
     */
    hours(minutes: number): string;
}

/** Every sentence CG-04's preview can produce (P2 Task 9, completed here). */
export interface PreviewMessages extends Vocabulary {
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

    /** Owner decision R: the days arrive from the caller; P2 stores none of them. No parameters. */
    unwantedDayBlock(): string;

    /** Owner decision T: day 1 is the join date, and an unknown join date is said OUT LOUD. */
    onboardingGrace(args: { days: number }): string;

    /** ISO integers only. The day NAMES are the server's (AR-07) and never appear in this package. */
    dowRestriction(args: { days: readonly number[] }): string;

    /** Owner decision S: post-call always, same-day optionally, and both by CALENDAR DAY. */
    clinicConflict(args: { variant: 'post_call' | 'post_call_and_same_day' }): string;

    /** Owner decision U: reading (a), and day exceptions LIFT the ban rather than applying it. */
    sameUnitConflict(args: { units: readonly string[]; exceptDates: readonly string[] }): string;

    /** Owner decision H: anchored on the END of the first duty, the second tested by its START. */
    postDutyExclusion(args: { from: readonly string[]; to: readonly string[]; hours: number }): string;

    /** Owner decision V: three units, and the transition allowance the hours one actually reads. */
    consecutiveMax(args: {
        count: number;
        unit: 'days' | 'nights' | 'hours';
        transitionMinutes: number;
        kinds: readonly string[];
    }): string;

    /** No parameters (CG-07's em dash). It says what ABUTTING means, the half people get wrong. */
    overlapBlock(): string;

    /** No parameters. It states the anchor-date reading, because that is what a reader queries. */
    vacationBlock(): string;

    /** Owner decision P: which slots are restricted, to what, and when the answer is READ. */
    eligibility(args: { slots: readonly SlotAllowanceText[] }): string;
}

/**
 * Every sentence a VIOLATION or a `coverage()` row can produce (P2-2 Task 1).
 *
 * ## These are read somewhere else, by somebody else, at a different moment
 *
 * A preview describes a rule on the gate screen before a draft exists. These describe a PLACEMENT
 * in a draft that exists — WB-03 badges the cell, and the sentence is what a scheduler reads when
 * they hover it. So the two are separate interfaces rather than one, and a type gets whichever half
 * its call is: `ConditionPreview` cannot reach a violation sentence and `ConditionEvaluator` cannot
 * reach a preview one.
 *
 * ## Every one of them is located, so none of them repeats the location
 *
 * A `Finding` carries a {@link Location} beside its explanation, and the location already says who
 * and where. The sentences therefore name the OTHER duty, the measured number and the authored
 * limit, and never the person the badge is already under.
 *
 * ## The three coverage reasons are here for the same reason the twelve above are
 *
 * A skipped window is text a scheduler reads beside the violations — *"1 evaluated, 1 skipped, and
 * here is why"* — and `coverage()` is half of the same contract change. A sentence that is English
 * wherever it happens to be assembled is not externalized because its neighbour is.
 */
export interface ViolationMessages extends Vocabulary {
    /**
     * Owner decision H, in its three shapes.
     *
     * `apart` is the number the PREDICATE measured — days between the two start dates, or minutes
     * between the earlier end and the later start — and `value` is the number the condition row
     * authored. Both are printed, because a shortfall without the limit does not say what was
     * wanted and a limit without the shortfall does not say how far off it is.
     *
     * `overlapping` is the degenerate case the `hours` reading reaches: two duties that overlap have
     * no gap at all, and *"only −3 h between them"* is a number nobody would believe.
     */
    minGapViolation(args: {
        unit: 'days' | 'hours';
        value: number;
        apart: number;
        overlapping: boolean;
        partner: DutyRef;
    }): string;

    /** The other half of the overlapping pair. Both placements get one, each naming the other. */
    overlapBlockViolation(args: { partner: DutyRef }): string;

    /** The anchor-date reading, said as a date rather than as a range. */
    vacationBlockViolation(args: { date: string }): string;

    /** Owner decision R: the day came from the person's own request, and this rule stores none. */
    unwantedDayBlockViolation(args: { date: string }): string;

    /**
     * Owner decision P, for whichever facet failed.
     *
     * `held` is `null` when the person holds NOTHING on the date, and that is a different sentence
     * rather than an empty pair of quotes: a person between two rotations is a real state, it fails
     * closed, and a reader shown `Rotating on unit ""` would read it as a data glitch.
     */
    eligibilityViolation(args: {
        facet: 'level' | 'rotation';
        held: string | null;
        date: string;
        slotKey: string;
        allowed: readonly string[];
    }): string;

    /** ISO integers on both sides. The day NAMES are the server's (AR-07) and never appear here. */
    dowRestrictionViolation(args: { date: string; isoWeekday: number; days: readonly number[] }): string;

    /** Owner decision U, reading (a): the unit is the one each of them rotates on THAT DAY. */
    sameUnitConflictViolation(args: { partners: readonly string[]; date: string; unitKey: string }): string;

    /**
     * Owner decision H: the clock runs from the END of the anchor, so `anchorEndsOn` is a date the
     * predicate resolved from the anchor's own slot window rather than the date it started on.
     */
    postDutyExclusionViolation(args: { hours: number; anchor: DutyRef; anchorEndsOn: string }): string;

    /** Owner decision V's `days`/`nights` reading: consecutive DATES, and where in the run this is. */
    consecutiveMaxDatesViolation(args: { unit: 'days' | 'nights'; run: number; count: number }): string;

    /** Owner decision V's `hours` reading: one chain, and the allowance that joined it. */
    consecutiveMaxHoursViolation(args: { minutes: number; transitionMinutes: number; count: number }): string;

    /** Owner decision S: post-call always, same-day only under the second variant. */
    clinicConflictViolation(args: {
        variant: 'post_call' | 'same_day';
        clinicKey: string;
        session: string;
        date: string;
    }): string;

    /**
     * A duty BEFORE the join date — deliberately one step past the literal reading of decision T.
     *
     * *"The first N days"* is a closed range, and a rota drafted before somebody starts puts duties
     * outside it on the EARLY side, where a range test reports nothing at all. Its own sentence,
     * because *"day 0 of the grace"* on a gate screen is a number nobody would believe.
     */
    onboardingGraceBeforeJoinViolation(args: { joinedAt: string }): string;

    /** Owner decision T: day 1 is the join date, and the sentence says which day this is. */
    onboardingGraceViolation(args: { day: number; days: number; joinedAt: string }): string;

    /** CG-01's on/off, reported rather than treated as silence. `coverage()` only. */
    inactiveConditionSkip(): string;

    /**
     * The unevaluable left edge, in the TWO shapes it comes in.
     *
     * `historyAvailableFrom` is `null` when no history was supplied at all, and a real date when it
     * was supplied but does not reach back past the horizon — a first-ever draft. The reason shipped
     * announcing the first for both, so the second printed a sentence contradicted by the very field
     * it named, and a coverage row a reader can catch out is one they stop reading.
     */
    carryInSkip(args: { horizonFrom: string; historyAvailableFrom: string | null }): string;

    /** Owner decision T's other half: the person whose join date nothing recorded, named out loud. */
    unknownJoinDateSkip(args: { personKey: string; placements: number }): string;
}

/** The whole table. What `EN` implements and what a second language would. */
export interface Messages extends PreviewMessages, ViolationMessages {}

/**
 * The English table, both halves.
 *
 * The wording is plain on purpose: CG-01 shows the preview text on the gate screen next to a drag
 * handle, and WB-03 shows the violation text on a badged cell, both to a scheduler who has not read
 * the spec and never will. Every sentence says what happens, with the department's own numbers in
 * it, and no sentence asks the reader to do arithmetic to find out what the rule permits.
 *
 * The two halves are told apart by a `…Violation` / `…Skip` suffix rather than by living in two
 * objects: a violation sentence and the preview it belongs beside share a stem, so a translator sees
 * the pair, and one grep finds every sentence one type can produce.
 *
 * Each method calls `EN.conjoin` rather than `this.conjoin`, deliberately. A sentence must render
 * identically whether it is reached through the table or destructured off it, and `this` is the one
 * thing about an object literal that does not survive being taken apart.
 */
export const EN: Messages = {
    conjoin(items) {
        if (items.length <= 1) {
            return items[0] ?? '';
        }

        return `${items.slice(0, -1).join(', ')} and ${items[items.length - 1] as string}`;
    },

    plural(count, one, many) {
        return `${count} ${count === 1 ? one : many}`;
    },

    hours(minutes) {
        const value = minutes / 60;

        return Number.isInteger(value) ? String(value) : value.toFixed(1);
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

    unwantedDayBlock() {
        return (
            'No duty on a day the person has registered as unwanted, counting the day the duty starts ' +
            'on — a night duty starting the evening before an unwanted day does not count against it. ' +
            'The days come from the request the person filed; this rule stores none of them itself.'
        );
    },

    onboardingGrace({ days }) {
        return (
            `No duty in a person's first ${EN.plural(days, 'day', 'days')} on the roster, counting ` +
            `their join date as day 1 — somebody joining on 1 Aug may first be scheduled on ` +
            `${days + 1} Aug. A person whose join date is not recorded is NOT blocked by this rule, ` +
            'and the evaluation reports whom it could not judge rather than passing them silently.'
        );
    },

    postDutyExclusion({ from, to, hours }) {
        const opens = from.length === 0 ? 'any duty' : `a duty of kind ${EN.conjoin(from as string[])}`;
        const blocked = to.length === 0 ? 'any duty' : `duties of kind ${EN.conjoin(to as string[])}`;
        const shared = (to as string[]).filter((kind) => (from as string[]).includes(kind));

        const both =
            shared.length === 0
                ? ''
                : ` Because ${EN.conjoin(shared)} is on both sides, this also spaces such duties from ` +
                  'each other by the same hours.';

        return (
            `After ${opens} ends, ${blocked} may not START for ${hours} h. The clock runs from the END ` +
            `of the first duty, so a longer duty pushes the block further out on its own.${both}`
        );
    },

    consecutiveMax({ count, unit, transitionMinutes, kinds }) {
        const over = kinds.length === 0 ? 'duties' : `duties of kind ${EN.conjoin(kinds as string[])}`;

        if (unit === 'hours') {
            return (
                `At most ${count} h of duty in one unbroken stretch, counting ${over}. Two duties ` +
                `${transitionMinutes} minutes or less apart are ONE stretch, so a handover does not ` +
                'restart the clock; a longer gap does.'
            );
        }

        const counted = unit === 'nights' ? ['night on duty', 'nights on duty'] : ['day on duty', 'days on duty'];

        return (
            `At most ${EN.plural(count, counted[0] as string, counted[1] as string)} in a row, counting ` +
            `${over} by the date each one starts on — two duties on one date are one date, and a night ` +
            `running past midnight belongs to the date it started. The ${transitionMinutes}-minute ` +
            'transition allowance is read only by the hours version of this rule.'
        );
    },

    clinicConflict({ variant }) {
        const postCall =
            'No clinic on a day a duty runs into: somebody whose night or 24 h call ends on the ' +
            'morning of a clinic day may not be at that clinic. Who a clinic comes down to is its ' +
            'own rule — everybody rotating on the unit, only the levels attached to it, or the ' +
            'people named on it — read on the day the clinic runs.';

        if (variant === 'post_call') {
            return `${postCall} A clinic on the day a duty STARTS is not a conflict under this setting.`;
        }

        return (
            `${postCall} A clinic on the day a duty starts is a conflict too, counted by CALENDAR ` +
            'DAY rather than by hours — a clinic session is a morning-or-afternoon code with no ' +
            'times attached, so there are no hours to compare.'
        );
    },

    sameUnitConflict({ units, exceptDates }) {
        const where =
            units.length === 0
                ? 'any one unit'
                : `${EN.conjoin(units as string[])}`;

        const lifted =
            exceptDates.length === 0
                ? 'The ban applies on every day of the schedule.'
                : `It does not apply on ${EN.conjoin(exceptDates as string[])}, where it is lifted.`;

        return (
            `Two people rotating on ${where} are never on call on the same day, judged by the ` +
            `rotation each of them is on that day rather than by where they started the year. ${lifted}`
        );
    },

    dowRestriction({ days }) {
        const which = EN.conjoin(days.map((day) => String(day)));

        return (
            `No duty on ISO ${days.length === 1 ? 'weekday' : 'weekdays'} ${which}, counting the day ` +
            'the duty starts on. The days are ISO numbers because the day names belong to the ' +
            "department's own calendar and are rendered by the server, never by this rule. Whom the " +
            "ban covers — a rotation, a level, named people — is the condition's scope, read on the " +
            'day of the duty.'
        );
    },

    overlapBlock() {
        return (
            'Nobody may hold two duties whose hours overlap, counting a night duty as running past ' +
            'midnight into the following day. Two duties that abut — one ending exactly when the next ' +
            'begins — do not overlap.'
        );
    },

    vacationBlock() {
        return (
            'No duty on a day the person is on leave, counting the day the duty starts on — the first ' +
            'and the last day of a leave period both count, and a night duty starting the evening ' +
            'before leave begins does not.'
        );
    },

    eligibility({ slots }) {
        if (slots.length === 0) {
            return 'No slot is restricted: anybody rostered may fill any slot.';
        }

        const clauses = slots.map(({ slotKey, levelKeys, unitKeys }) => {
            const parts: string[] = [];

            if (levelKeys.length > 0) {
                parts.push(`levels ${EN.conjoin(levelKeys)}`);
            }

            if (unitKeys.length > 0) {
                parts.push(`rotations ${EN.conjoin(unitKeys)}`);
            }

            return `${slotKey} takes ${parts.length === 0 ? 'anybody' : EN.conjoin(parts)}`;
        });

        return (
            `Who may fill which slot: ${clauses.join('; ')}. A person is judged by the level and the ` +
            'rotation they hold on the day of the duty, so a promotion part-way through changes the ' +
            'answer from that day on. Slots not named here are unrestricted.'
        );
    },

    minGapViolation({ unit, value, apart, overlapping, partner }) {
        if (overlapping) {
            return (
                `This duty overlaps "${partner.slotKey}" on ${partner.date}, so the ` +
                `required ${value} h gap between them is not there at all.`
            );
        }

        if (unit === 'hours') {
            return (
                `Only ${EN.hours(apart)} h between this duty and "${partner.slotKey}" on ` +
                `${partner.date}; at least ${value} h is required between the ` +
                'end of one duty and the start of the next.'
            );
        }

        return (
            `${EN.plural(apart, 'day', 'days')} between this duty and "${partner.slotKey}" on ` +
            `${partner.date}, counted between the dates they start on; at least ` +
            `${value} are required.`
        );
    },

    overlapBlockViolation({ partner }) {
        return `Overlaps "${partner.slotKey}" on ${partner.date}.`;
    },

    vacationBlockViolation({ date }) {
        return `On leave on ${date}.`;
    },

    unwantedDayBlockViolation({ date }) {
        return `${date} is registered as an unwanted day.`;
    },

    eligibilityViolation({ facet, held, date, slotKey, allowed }) {
        const holding =
            facet === 'level'
                ? held === null
                    ? 'Holds no level'
                    : `Holds level "${held}"`
                : held === null
                  ? 'Rotating on no unit'
                  : `Rotating on unit "${held}"`;

        return `${holding} on ${date}; slot "${slotKey}" is limited to ${EN.conjoin(allowed)}.`;
    },

    dowRestrictionViolation({ date, isoWeekday, days }) {
        return (
            `${date} is ISO weekday ${isoWeekday}, and this rule bans ISO ` +
            `${days.length === 1 ? 'weekday' : 'weekdays'} ${EN.conjoin(days.map((day) => String(day)))}.`
        );
    },

    sameUnitConflictViolation({ partners, date, unitKey }) {
        return (
            `Also on call with ${EN.conjoin(partners.map((key) => `"${key}"`))} on ${date}, and ` +
            `${partners.length === 1 ? 'both are' : 'all of them are'} rotating on ${unitKey}.`
        );
    },

    postDutyExclusionViolation({ hours, anchor, anchorEndsOn }) {
        return (
            `Starts inside the ${hours} h exclusion after "${anchor.slotKey}" on ` +
            `${anchor.date}, which ends ${anchorEndsOn}.`
        );
    },

    consecutiveMaxDatesViolation({ unit, run, count }) {
        const noun = unit === 'nights' ? 'Night' : 'Day';
        const nouns = unit === 'nights' ? 'duty nights' : 'duty days';

        return `${noun} ${run} of a run of consecutive ${nouns}; the cap is ${count}.`;
    },

    consecutiveMaxHoursViolation({ minutes, transitionMinutes, count }) {
        return (
            `This duty extends a continuous stretch to ${EN.hours(minutes)} h, counting duties ` +
            `${transitionMinutes} minutes or less apart as one; the cap is ${count} h.`
        );
    },

    clinicConflictViolation({ variant, clinicKey, session, date }) {
        if (variant === 'same_day') {
            return (
                `Same day: clinic "${clinicKey}" (session ${session}) runs on ${date}, ` +
                'the date this duty starts on.'
            );
        }

        return `Post-call: clinic "${clinicKey}" (session ${session}) runs on ${date}, after this duty ends.`;
    },

    onboardingGraceBeforeJoinViolation({ joinedAt }) {
        return `Before the join date ${joinedAt}.`;
    },

    onboardingGraceViolation({ day, days, joinedAt }) {
        return (
            `Day ${day} of the ${days}-day onboarding grace, ` +
            `counting the join date ${joinedAt} as day 1.`
        );
    },

    inactiveConditionSkip() {
        return 'The condition is inactive (CG-01 on/off), so nothing was evaluated.';
    },

    carryInSkip({ horizonFrom, historyAvailableFrom }) {
        return (
            (historyAvailableFrom === null
                ? `No duty history was supplied before ${horizonFrom} (historyAvailableFrom is null)`
                : `Duty history begins at ${historyAvailableFrom}, which is not before ${horizonFrom}`) +
            ', so a duty running past midnight into the horizon cannot be seen.'
        );
    },

    unknownJoinDateSkip({ personKey, placements }) {
        return (
            `No join date is recorded for "${personKey}", so ${placements} ` +
            `${placements === 1 ? 'placement was' : 'placements were'} not evaluated. An unknown join ` +
            'date is no violation (owner decision T), and this row is what distinguishes that from ' +
            'a rule that ran and found nothing.'
        );
    },
};
