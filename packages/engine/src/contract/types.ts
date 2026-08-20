/**
 * The CG-10 contract: `(schedule, context, conditions) → violations[]`, and the shapes either side.
 *
 * ## What this file is the only definition of
 *
 * Every condition type in the catalog constrains DUTIES, and SL-01..SL-07 — the tables that will
 * one day store a slot and an assignment — are P3. So P2 authors the duty shape as a TYPE in its
 * own contract (Decision A, owner decision C), and that type is the only definition of a duty in
 * this repository until P3 serialises into it. `Slot` and `Duty` themselves live one directory
 * along, in `duty/interval.ts`, beside the arithmetic that reads them; everything else the engine
 * is handed is here.
 *
 * The alternative — letting each type invent the duty facts it needs — is twenty-two definitions
 * of one fact, which is the defect `AuditChain::canonical()` already carries a docblock against.
 *
 * ## `location` is the ONE place CG-10 is widened, and it happens HERE
 *
 * CG-10 fixes a violation as `{conditionId, severity, rank?, location, explanation}` and leaves
 * `location` unshaped. Eleven of the twenty-two types violate at a PLACEMENT; eight violate per
 * (person, WINDOW); three violate per COHORT with no date and no slot at all. `max_gap` forces the
 * question on its own: its violation is an interval between two duties, or an interval left OPEN
 * at the horizon edge.
 *
 * So {@link Location} is a three-member discriminated union, authored in full before the first
 * predicate exists (Decision D). Retrofitting the other two members after eleven types, a JSON
 * Schema and a fixture corpus have been written against `{personKey, date, slotKey}` is the exact
 * cost this task is paid to avoid — and it is what makes CG-10's *"new types are additive"* true
 * rather than aspirational, since P2-2's eleven keys add predicates and touch no shared shape.
 *
 * ## The return type does NOT widen, and skipped windows have their own function
 *
 * `evaluate()` returns `Violation[]`, exactly CG-10's sentence, because PU-03's publish dialog
 * *"consumes `violations[]` unchanged"*. A window a floor could not evaluate is therefore NOT
 * smuggled into the violation list as a third severity — it is reported by the sibling
 * `coverage()`. See `coverage.ts` for why that separation is load-bearing rather than tidy.
 *
 * ## What this contract deliberately does NOT carry
 *
 * AU-02's solver request has two members with no P2 counterpart, and they are **absent rather than
 * empty**, so P4 finds a hole rather than a wrong default:
 *
 *  - **`templates`** — SL-03's coverage templates. Nothing in this repository stores one, and an
 *    empty array here would read as *"this department has no coverage requirements"*, which is a
 *    different and false statement.
 *  - **`constraints`** — RQ-01's approved requests. The request store is Stage 3; the one piece of
 *    it P2 needs, a person's unwanted days, arrives on {@link Person} from the caller instead
 *    (owner decision R, answered 2026-08-20: P2 stores nothing).
 *
 * The rest of AU-02 serialises out of these shapes with no translation layer: `days`+`periods` is
 * `periodSkeleton`, `people` is `roster`, `slots` is `slots`, `Schedule.duties` is
 * `fixedAssignments`, and `conditions` is `conditions`.
 */

import type { Duty, Slot } from '../duty/interval';
import type { Horizon } from '../duty/windows';
import type { DayType } from '../calendar';
import type { Ymd } from '../calendar/ymd';

/**
 * One date of the horizon, PRECOMPUTED server-side by the one converter.
 *
 * `dayType` is `Calendar::dayType()`'s answer and is never re-derived here: holiday wins over
 * weekend deliberately, and a mirror re-deriving it from `weekendDays` alone would silently
 * disagree on every holiday that falls on a weekend — green on exactly the days it most matters.
 * `holidays[].year` carries the holiday's OWN-CALENDAR year (owner decision W), which is what lets
 * `holiday_equity` key a Hijri rule's credit to a Hijri year without any Hijri code in the browser.
 */
export interface Day {
    date: Ymd;
    isoWeekday: number;
    dayType: DayType;
    periodKey: string | null;
    holidays: { key: string; year: number }[];
}

/**
 * A week of a period, with the CLIPPED bounds the department's own rule produces.
 *
 * Computed server-side by `Calendar::weeksIn()` and carried, never mirrored (owner decision O):
 * `golden.json` has zero coverage of the clipped bounds, so a mirror implementation would be an
 * unasserted second definition of a per-department fact.
 */
export interface Week {
    startsOn: Ymd;
    endsOn: Ymd;
    clippedStartsOn: Ymd;
    clippedEndsOn: Ymd;
}

/** A block of the academic year, and the weeks it is measured in. */
export interface Period {
    key: string;
    startsOn: Ymd;
    endsOn: Ymd;
    weeks: Week[];
}

/**
 * A dated span of one fact about a person — the level they hold, or the unit they rotate on.
 *
 * `key` is a STABLE key, never a database id (owner decision G): `people.id` and `users.id` are
 * independent sequences, ids are instance-local, and `RotaExport` already refuses them for person
 * identity on exactly that ground.
 */
export interface Span {
    key: string;
    from: Ymd;
    to: Ymd;
}

/**
 * One member of the roster, as the engine reads them.
 *
 * `eligibleDays` is the availability denominator and is the reason four separate types are not
 * quietly wrong: `fairness_distribution` pro-rates against it, `max_gap` is suppressed by it,
 * `target_per_period` modifies against it, and owner decision J (answered 2026-08-20, overriding
 * its own default) makes it `call_frequency_max`'s denominator too — days on leave, before
 * `joinedAt` and off the roster are removed, so the constraint TIGHTENS around somebody's leave
 * rather than back-loading them into it.
 *
 * `priorCredits` is `number | null` per holiday key, and `null` means UNKNOWN — distinct from a
 * known zero (owner decision W). Encoding year-one absence as zero makes `holiday_equity`'s
 * lookback silently do nothing in year one and actively mis-schedule in year two.
 */
export interface Person {
    key: string;
    levelSpans: Span[];
    unitSpans: Span[];
    leaveDays: Ymd[];
    unwantedDays: Ymd[];
    eligibleDays: Ymd[];
    external: boolean;
    joinedAt?: Ymd;
    priorCredits?: Record<string, number | null>;
}

/**
 * A clinic, with its attendee mode and rows.
 *
 * The mode and its two lists are carried because `clinic_conflict` is wrong in BOTH directions
 * without them: a `named` clinic conflicts with the people named on it and nobody else, and a
 * `levels` clinic with whoever holds those levels on the date. `session` is a two-character code
 * with no minutes anywhere in the schema, which is why the optional same-day variant of that type
 * is a CALENDAR-DAY overlap rather than a minute one (owner decision S).
 */
export interface Clinic {
    key: string;
    unitKey: string;
    isoWeekday: number;
    session: string;
    active: boolean;
    attendeeMode: 'rotators' | 'levels' | 'named';
    attendeeLevelKeys: string[];
    attendeePersonKeys: string[];
}

/**
 * Everything the engine is told, and nothing it works out for itself.
 *
 * `timezone` is PROVENANCE AND FIXTURE IDENTITY ONLY and is never read by any arithmetic in this
 * package — there is no instant here to place in it (Decision B). It is carried so a corpus case
 * records which department it was taken from, and `test/contract.test.ts` asserts that no module
 * under `src/` so much as mentions it. An engine that reads a timezone has acquired an instant.
 *
 * `today` ARRIVES; it is never computed. `weekendDays` and `weekStartIsoDay` arrive too (owner
 * decision X) — a bundled default would be a second definition of a per-department fact, which is
 * precisely what `golden.json` and AR-08 exist to prevent.
 *
 * `priorDuties` and `followingDuties` are the carry-in tail, READ-ONLY. Every window-measured and
 * pairwise type measures a relationship that crosses the boundary of what is being evaluated — the
 * gap between the last night of month M and the first duty of M+1, a run spanning the 31st into the
 * 1st — and a draft is built one period at a time against a preceding period that is a different,
 * already-published schedule. Reading them is not re-evaluating them: the emission rule in
 * `evaluate.ts` is what keeps CG-03's *"never retroactive on published schedules"* intact.
 * `followingDuties` is usually empty and is never ASSUMED empty.
 */
export interface EvaluationContext {
    timezone: string;
    weekStartIsoDay: number;
    weekendDays: number[];
    today: Ymd;
    days: Day[];
    periods: Period[];
    people: Person[];
    slots: Slot[];
    clinics: Clinic[];
    /** How far back real duty history reaches, or `null` when there is none. */
    historyAvailableFrom: Ymd | null;
    priorDuties: Duty[];
    followingDuties: Duty[];
}

/** What is being evaluated: the horizon, and the duties placed inside it. */
export interface Schedule {
    horizon: Horizon;
    duties: Duty[];
}

/** CG-01's scope: which population a condition applies to. Absent members mean "no filter". */
export interface ConditionScope {
    unitKeys?: string[];
    levelKeys?: string[];
    personKeys?: string[];
}

/**
 * CG-05's Hard and CG-06's soft, and nothing else.
 *
 * A third member would collide with `class` being AUTHORED DATA on the condition row: §30 makes it
 * a field, CG-01 lists it per condition and CG-02 rank-orders the soft ones. The engine reads that
 * field. It does not decide it.
 */
export type Severity = 'hard' | 'soft';

/**
 * One row of the conditions gate, as the engine reads it. P3 builds the table and the screen.
 *
 * `class` is authored and the engine never overrides it (Decision E). `rank` is CG-02's drag order
 * and is meaningful for soft conditions only. `active` is CG-01's on/off — and a condition that is
 * off is not silently ignored: it appears in `coverage()` with nothing evaluated and the reason
 * stated, because a control that appears to do nothing is this codebase's most expensive recurring
 * failure shape (rulings 41 and 49).
 */
export interface Condition {
    id: string;
    typeKey: string;
    class: Severity;
    rank?: number;
    active: boolean;
    /** CG-01's "source" column: which preset or hand-edit this row came from. Opaque here. */
    source?: string;
    scope?: ConditionScope;
    params: Record<string, unknown>;
}

/** Which member of {@link Location} a type produces. Declared per registry entry (Decision E). */
export type LocationKind = 'placement' | 'window' | 'cohort';

/**
 * WHERE a violation is, in the only three shapes this catalog produces.
 *
 * `contributing` is MANDATORY on a window violation and optional on a cohort one. Without it a
 * duty-hours violation is unactionable in the workbench: it tells a scheduler that a window is over
 * budget and nothing about which placement to move. WB-03 badges cells and WB-04 orders pickers by
 * a fitness signal, and both need duties rather than a range. A cohort violation may have none —
 * *"this level is unevenly loaded"* is a statement about people, not about a placement.
 */
export type Location =
    | { kind: 'placement'; personKey: string; date: Ymd; slotKey: string }
    | { kind: 'window'; personKey: string; from: Ymd; to: Ymd; contributing: Duty[] }
    | { kind: 'cohort'; personKeys: string[]; scopeLabel: string; contributing?: Duty[] };

/**
 * CG-10's violation, with `location` shaped and nothing else added.
 *
 * Exactly five fields. `typeKey` is deliberately NOT among them, tempting as it is: CG-10 fixes the
 * shape, PU-03's publish dialog consumes it unchanged, and a consumer that needs the type reads the
 * condition row it already has the id of.
 */
export interface Violation {
    conditionId: string;
    severity: Severity;
    rank?: number;
    location: Location;
    explanation: string;
}

/**
 * What one type produces at one location, BEFORE `evaluate()` stamps the condition's own facts on.
 *
 * A type says WHERE and WHY. It never says how severe, because severity is `Condition.class` and a
 * type deciding it for itself would be twenty-two chances to override authored data. Stamping it
 * centrally makes Decision E's *"the engine still never overrides the row"* structural rather than
 * something twenty-two files each have to remember.
 */
export interface Finding {
    location: Location;
    explanation: string;
}

/** A window a type could not honestly evaluate, and why it could not. */
export interface SkippedWindow {
    from: Ymd;
    to: Ymd;
    reason: string;
}

/** What one type actually measured: how many windows, and which it had to leave alone. */
export interface CoverageDetail {
    evaluatedWindows: number;
    skipped: SkippedWindow[];
}

/** {@link CoverageDetail} with the condition it belongs to — `coverage()`'s row. */
export interface CoverageReport {
    conditionId: string;
    evaluatedWindows: number;
    skipped: SkippedWindow[];
}

/**
 * The single call a type answers, producing findings and coverage TOGETHER.
 *
 * One producer with two projections, deliberately. Two independent functions could disagree — a
 * type reporting a window as skipped in `coverage()` while still firing on it in `evaluate()` — and
 * that disagreement is invisible on a green suite. Returning both from one call makes the two
 * consistent by construction rather than by twenty-two authors remembering.
 */
export type ConditionEvaluator = (
    condition: Condition,
    schedule: Schedule,
    context: EvaluationContext,
) => ConditionOutcome;

/** What {@link ConditionEvaluator} returns. */
export interface ConditionOutcome {
    findings: Finding[];
    coverage: CoverageDetail;
}

/** CG-04's plain-language preview of one condition's parameters. Task 9 fills these in. */
export type ConditionPreview = (condition: Condition, context: EvaluationContext) => string;

/**
 * One case of the golden corpus: the inputs, the expected outputs, and why the case exists.
 *
 * Declared in the contract rather than in the test suite because the corpus is the contract's
 * regression gate (NF-08/QA-01) and P2-2, Task 23's CI job and Task 24's demo command all load
 * cases from disk. A format that lives only in whichever suite happens to read it is a format the
 * next reader half-implements.
 *
 * `why` is MANDATORY and it is not decoration: a fixture whose purpose nobody wrote down ossifies,
 * because the next author cannot tell whether the number in it is the point of the case or a value
 * that happened to make the assertion pass. `test/fixtures/README.md` states the rest, including
 * that the corpus is SYNTHETIC permanently.
 */
export interface Fixture {
    name: string;
    why: string;
    context: EvaluationContext;
    schedule: Schedule;
    conditions: Condition[];
    expected: Violation[];
    expectedCoverage?: CoverageReport[];
}
