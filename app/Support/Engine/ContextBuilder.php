<?php

namespace App\Support\Engine;

use App\Models\Clinic;
use App\Models\Level;
use App\Models\MasterRotaAssignment;
use App\Models\Period;
use App\Models\Person;
use App\Models\PersonLevel;
use App\Models\Unit;
use App\Models\Vacation;
use App\Support\Calendar;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use InvalidArgumentException;

/**
 * THE ONE PLACE this department's shipped data becomes CG-10's `EvaluationContext`
 * (Munawib design §14 item 22, P2 Decision I).
 *
 * `RotaGrid` → `AvailabilitySummary`, one layer along: a single bounded-query loader producing an
 * immutable array, then a pure fold. Without one builder, every P3 consumer builds its own context
 * and the bounded-query property is lost the first time — the same argument that made
 * `AvailabilitySummary` one fold feeding two screens.
 *
 * ## THIS FILE IS A READER. IT IS NOT A RULE, AND IT IS NOT A RULE IN DISGUISE
 *
 * Design §4.1 — *"No PHP implementation of the rules exists anywhere. That is the point."* — is
 * scoped to rule SEMANTICS, not to data access, and this paragraph exists because a loader is
 * exactly the artifact that reads as a breach of that sentence in review. Loading spans and
 * flattening them into dated facts implements nothing.
 *
 * The real risk is not PHP rules code; it is THE SERIALISER, where a rule tries to arrive as an
 * optimisation. Sending only the people who could legally take a duty is one catalog type
 * re-implemented as a `where`, and it fails in the direction nobody sees: the engine reports no
 * finding, because the row it would have flagged was never sent. So this builder narrows by the
 * HORIZON and by nothing else. Everything a condition would judge — a person on leave for the whole
 * block, a person with no rotation at all, a person whose join date nobody typed, an external, a
 * deactivated clinic — arrives, described, for the engine to judge or not judge.
 * `EngineHoldsNoRuleTest` needles for the query shapes and asserts the population positively;
 * `EngineIsAReaderTest` asserts the writer half.
 *
 * ## WHERE IT LIVES IS FORCED, NOT CHOSEN
 *
 * `App\Support\Rota\*` is globbed by `RotaAccessTest` with `eligib` among its needles, and
 * `App\Support\Clinics\*` by `ClinicHooksTest` with `condition` among its. Either would fail the
 * build on this file's own vocabulary. `App\Support\Engine\` is clear of both, and P2 adds no
 * allow-list entry to either — MR-04's rule is that *the rota* must not infer who may take call,
 * and CL-03's is that *the clinic module* must not evaluate rules; both are satisfied by the
 * crossing living in the engine's own namespace and reading those modules' data.
 *
 * ## WHAT IS CARRIED, AND WHAT ONLY THE CALLER CAN KNOW
 *
 * Carried, from tables that exist: the day vector (`Calendar::dayFacts()`), the periods and their
 * clipped week windows (`Calendar::weeksIn()`), each person's unit spans and level spans, the dates
 * they are on leave, the dates they are available, the clinics with their attendee rules, and the
 * department's week shape.
 *
 * Supplied by the caller, because the shipped schema records none of it: `slots` (SL-01's kind
 * vocabulary is stored nowhere in this repository — P2 Task 1 finding 2 — so a slot is not
 * inventable here), `priorDuties`/`followingDuties` and `historyAvailableFrom` (no table records a
 * duty anybody has ever held), and each person's unwanted days (owner decision R: P2 stores
 * nothing; the store lands with the requests feature that owns it).
 *
 * NOT carried at all, deliberately: prior holiday credits. Owner decision W starts everybody at
 * zero, and there is nothing in the schema to compute anything else from — a zero map written here
 * would be indistinguishable from a measured one, which is the state that decision explicitly
 * wants readable rather than assumed.
 *
 * ## IT NAMES NOBODY, AND IT TAKES NO VIEWER
 *
 * `RotaGrid::forYear()` and `ClinicRoster::forDate()` take no viewer either — the parameter was
 * REMOVED rather than ignored, after passing the real user made the rota grid ship every
 * colleague's contact detail in its Inertia props with nothing rendering them (P1d-2 Decision C, a
 * live disclosure on a DEFAULT department). This payload is the same shape and gets a stronger
 * answer: it carries no contact field, no free text and not even a name, for any viewer, because
 * no condition type reads one. `ContextBuilderTest::test_no_contact_field_can_reach_the_payload`
 * asserts that over the serialised payload BY KEY NAME on the most permissive institution setting
 * the system can produce, and `ContactFieldsAreProjectedOnceTest` fails the build on the source.
 *
 * That is also why `PersonPresenter` is deliberately not used: it is the one path from a person to
 * a SCREEN's props, and its whole output — a name, a short name, a position — is what an engine
 * context must not acquire. Routing through it would ADD the disclosure this file exists without.
 *
 * ## THE N+1 TRAPS THIS CODEBASE HAS ALREADY PAID FOR
 *
 * Each is a real defect, each is why a line below looks the way it does, and each was PLANTED
 * against the populated fixture and watched red rather than reasoned about. The budget is
 * `ContextBuilderTest::test_the_context_is_a_bounded_number_of_queries`; the honest count is
 * THIRTEEN and the bound is 17.
 *
 *  - A per-person level lookup instead of `Person::levelSpansBetween()` fetched once for the whole
 *    roster. Planted as `Person::find(…)->levelAt(…)` per span: **223 queries.**
 *  - `$assignment->unit` per span instead of the id-keyed map built by query 7. Planted:
 *    **113 queries.** Nothing below touches that relation.
 *  - A narrowed `select()`/`pluck()` or a constrained `with()` on a person query. This is the one
 *    that bites hardest and it bites differently here than elsewhere: `full_name` and `position`
 *    are read-through accessors onto the linked roster row (P0c) and would resolve to NULL with no
 *    error — but this context reads neither, so the classic symptom is absent and the REAL damage
 *    is one column further along. Planted as `select(['id', 'external'])`, the join date silently
 *    vanished for everybody, which on the far side of the contract is `onboarding_grace` reporting
 *    every person as unknown and firing on nobody — the exact shape owner decision T's coverage row
 *    exists to make visible. Caught by
 *    `test_eligible_days_remove_leave_and_the_dates_before_a_join_date`, not by the budget: a
 *    projection is CHEAPER, so no query count would ever have found it. WHOLE models are fetched
 *    for that reason.
 *  - `PersonPresenter` without `withExists(['user as has_account'])` → one EXISTS per row. Not
 *    reachable here, because the presenter is not used; recorded so the absence reads as a decision.
 *
 * The count does not move with the roster, the periods, the spans, the leave or the clinics, and
 * `test_the_query_count_does_not_grow_with_the_roster` is what says so — with both measurements
 * taken COLD, because `Calendar`'s settings and holiday reads are memoized per process and a warm
 * second call is two queries cheaper for a reason that has nothing to do with the roster.
 *
 * ## DATES
 *
 * Every one goes through `App\Support\Calendar` (AR-08). The comparisons below are string
 * comparisons on `Y-m-d` values already formatted by it — the idiom `RotaGrid::cellFor()` and
 * `AvailabilitySummary` share, and correct because that format sorts as text exactly as it sorts as
 * time. The two QUERIES below that compare a date to a stored `date`-cast column use `whereDate()`,
 * which is not decoration: a bare comparison drops the boundary day, so a person would appear on a
 * rotation from their second day on it and nothing would report an error. (The leave read gets the
 * same treatment inside `Vacation::scopeIntersecting()`, which is why it is called rather than
 * restated.)
 *
 * `institution_id` is provenance (D11) and is never a filter here. The database is the boundary.
 */
final class ContextBuilder
{
    /**
     * The end carried for a dated fact that has none.
     *
     * `Span.to` is not nullable in the CG-10 contract, and the engine reads a date outside every
     * span as *"this person holds nothing then"* — a real state it must not be told wrongly. A
     * level span with no `effective_to` is open, and clipping it to the horizon's last date would
     * say a person holds no level on the day after it, which is where `clinic_conflict`'s post-duty
     * window legitimately looks. So an open span is carried open, at the largest date the
     * contract's own `Ymd` shape can express.
     */
    public const NO_KNOWN_END = '9999-12-31';

    /**
     * A person's key in the engine's world.
     *
     * NOT `people.id` bare, and that is owner decision G's point rather than decoration:
     * `people.id` and `users.id` are independent sequences that this codebase has already been
     * bitten by comparing, and a prefixed key cannot be moved between them by accident. There is no
     * stable person CODE in this schema and P2 may not add one — it ships no migration — so the key
     * is derived rather than stored, and it is opaque to the engine by contract.
     */
    public static function personKey(Person $person): string
    {
        return 'p'.$person->getKey();
    }

    /**
     * @param  list<array<string, mixed>>  $slots  SL-01's vocabulary is stored nowhere in this
     *                                             repository, so a slot cannot be loaded and is not invented here
     * @param  list<array<string, mixed>>  $priorDuties  already-published duties before the horizon
     * @param  list<array<string, mixed>>  $followingDuties  and after it; usually empty, never assumed so
     * @param  string|null  $historyAvailableFrom  how far back real duty history reaches
     * @param  array<string, list<string>>  $unwantedDays  registered preferences, keyed by person key
     * @return array<string, mixed> CG-10's `EvaluationContext`
     *
     * @throws InvalidArgumentException
     */
    public static function forHorizon(
        string $fromYmd,
        string $toYmd,
        array $slots = [],
        array $priorDuties = [],
        array $followingDuties = [],
        ?string $historyAvailableFrom = null,
        array $unwantedDays = [],
    ): array {
        $from = Calendar::ymd($fromYmd);
        $to = Calendar::ymd($toYmd);

        if ($to < $from) {
            throw new InvalidArgumentException('An evaluation horizon ends before it starts.');
        }

        $dates = Calendar::datesBetween($from, $to);

        // Query 1 — the periods the horizon touches. A horizon spanning a block boundary touches
        // two, and a date inside none of them carries a null period key rather than being dropped.
        $periods = Period::query()
            ->whereDate('starts_on', '<=', $to)
            ->whereDate('ends_on', '>=', $from)
            ->orderBy('starts_on')
            ->get();

        // Query 2 — every rotation span the horizon touches, for the WHOLE department in one shot.
        // It comes before the roster because it is what says which people are described even though
        // they are no longer on it.
        $assignments = MasterRotaAssignment::query()
            ->whereDate('starts_on', '<=', $to)
            ->whereDate('ends_on', '>=', $from)
            ->orderBy('starts_on')
            ->get();

        $spansByPerson = [];

        foreach ($assignments as $assignment) {
            $spansByPerson[(int) $assignment->person_id][] = $assignment;
        }

        // Query 3 — the roster. WHOLE models, never a projection.
        $active = Person::query()->active()->orderBy('people.id')->get();

        // Query 4 — and anybody who still holds a rotation in this horizon but has left the active
        // roster. `RotaGrid`'s stale-row union, for a sharper reason here: a duty naming a person
        // the context does not describe THROWS in the engine, so a departed colleague still on the
        // rota must be described or a whole evaluation dies on their one duty. `withTrashed()`
        // because a retired person's spans outlive them exactly as an inactive person's do.
        $strandedIds = array_values(array_diff(array_keys($spansByPerson), $active->modelKeys()));

        $stranded = $strandedIds === []
            ? new EloquentCollection
            : Person::query()->withTrashed()->whereIn('id', $strandedIds)->get();

        /** @var EloquentCollection<int, Person> $people */
        $people = $stranded->isEmpty()
            ? $active
            : $active->concat($stranded)->sortBy(fn (Person $person): int => (int) $person->getKey())->values();

        // Query 5 (+ its own eager 'level' load) — every level span intersecting the horizon, once
        // for the whole roster. Never `levelAt()` per person.
        $levelSpans = Person::levelSpansBetween($people, $from, $to);

        // Query 6 — every leave span intersecting the horizon, for this roster, grouped in PHP.
        $personIds = $people->modelKeys();

        $vacations = $personIds === []
            ? new EloquentCollection
            : Vacation::query()->whereIn('person_id', $personIds)->intersecting($from, $to)->get();

        $leaveByPerson = [];

        foreach ($vacations as $vacation) {
            $leaveByPerson[(int) $vacation->person_id][] = $vacation;
        }

        // Query 7 — the id → code map every rotation span resolves through. NOT narrowed to the
        // active units: a span written while a unit was live must keep its code the day that unit
        // is retired, or a person's rotation history silently loses its name.
        $unitCodes = Unit::query()->get()->mapWithKeys(
            fn (Unit $unit): array => [(int) $unit->getKey() => (string) $unit->code]
        )->all();

        // Query 8 — the same for levels, which the clinic attendee rules key on. The level SPANS
        // above already carry their own level through the eager load.
        $levelCodes = Level::query()->get()->mapWithKeys(
            fn (Level $level): array => [(int) $level->getKey() => (string) $level->code]
        )->all();

        // Queries 9 and 10 — the clinics and their attendee rules, eager-loaded. Every clinic,
        // active or not: whether a retired clinic can produce a finding is the engine's call.
        $clinics = Clinic::query()->with('attendees')->orderBy('id')->get();

        return [
            // Provenance and fixture identity only — the engine holds no instant to place in it.
            'timezone' => Calendar::timezone(),
            'weekStartIsoDay' => Calendar::weekStartIsoDay(),
            'weekendDays' => Calendar::weekendDays(),
            // "Today" ARRIVES; the engine never computes one.
            'today' => Calendar::todayYmd(),
            'days' => self::dayVector($dates, $periods),
            'periods' => self::periodWindows($periods),
            'people' => self::roster($people, $dates, $spansByPerson, $levelSpans, $leaveByPerson, $unitCodes, $unwantedDays),
            'slots' => array_values($slots),
            'clinics' => self::clinicRules($clinics, $unitCodes, $levelCodes),
            'historyAvailableFrom' => $historyAvailableFrom === null ? null : Calendar::ymd($historyAvailableFrom),
            'priorDuties' => array_values($priorDuties),
            'followingDuties' => array_values($followingDuties),
        ];
    }

    /**
     * One entry per date of the horizon, resolved ONCE server-side.
     *
     * P2 Task 1 finding 6 is why this is precomputed rather than asked per type: `dayType()` is
     * query-free but CPU-expensive — 30 days cost roughly 24 ms with four holidays configured, the
     * same 30 days re-asked nine times cost roughly 203 ms, and with twenty-two types active the
     * re-ask factor is worse rather than better. `Calendar::dayFacts()` answers the weekday, the
     * day type and the holiday occurrences in one walk, which halves even this single pass.
     *
     * @param  list<string>  $dates
     * @param  EloquentCollection<int, Period>  $periods
     * @return list<array<string, mixed>>
     */
    private static function dayVector(array $dates, EloquentCollection $periods): array
    {
        $bounds = $periods->map(fn (Period $period): array => [
            'key' => self::periodKey($period),
            'from' => $period->starts_on->format(Calendar::YMD),
            'to' => $period->ends_on->format(Calendar::YMD),
        ])->all();

        $out = [];

        foreach ($dates as $date) {
            $facts = Calendar::dayFacts($date);
            $periodKey = null;

            foreach ($bounds as $bound) {
                if ($date >= $bound['from'] && $date <= $bound['to']) {
                    $periodKey = $bound['key'];

                    break;
                }
            }

            $out[] = [
                'date' => $date,
                'isoWeekday' => $facts['iso_weekday'],
                // The one converter's answer, carried rather than re-derived. A mirror deriving it
                // from the weekend days alone would disagree on every holiday that falls on a
                // weekend — green on exactly the days it most matters.
                'dayType' => $facts['day_type'],
                'periodKey' => $periodKey,
                'holidays' => array_map(fn (array $occurrence): array => [
                    'key' => 'h'.$occurrence['holiday']->getKey(),
                    'year' => $occurrence['year'],
                ], $facts['holidays']),
            ];
        }

        return $out;
    }

    /**
     * The periods, each with the department's OWN week windows.
     *
     * Owner decision O: the week windows are computed HERE by `Calendar::weeksIn()` and carried,
     * never mirrored in the engine — `golden.json` has zero coverage of the clipped bounds, so a
     * second implementation of them would be an unasserted second definition of a per-department
     * fact. The clipped bounds are the ones that matter: a period rarely begins on a week boundary,
     * and a week-windowed count measured against the true bounds would count days outside its own
     * block.
     *
     * @param  EloquentCollection<int, Period>  $periods
     * @return list<array<string, mixed>>
     */
    private static function periodWindows(EloquentCollection $periods): array
    {
        return $periods->map(fn (Period $period): array => [
            'key' => self::periodKey($period),
            'startsOn' => $period->starts_on->format(Calendar::YMD),
            'endsOn' => $period->ends_on->format(Calendar::YMD),
            'weeks' => array_map(fn (array $week): array => [
                'startsOn' => $week['starts_on'],
                'endsOn' => $week['ends_on'],
                'clippedStartsOn' => $week['clipped_starts_on'],
                'clippedEndsOn' => $week['clipped_ends_on'],
            ], Calendar::weeksIn($period->starts_on, $period->ends_on)),
        ])->values()->all();
    }

    /**
     * `periods.academic_year` + `periods.position` is the table's own UNIQUE key
     * (`periods_year_position_unique`), so it is the stable name a stored rule can hold without
     * depending on an instance-local id.
     */
    private static function periodKey(Period $period): string
    {
        return $period->academic_year.'-'.sprintf('%02d', (int) $period->position);
    }

    /**
     * Every person the horizon describes, and every dated fact about them the engine reads.
     *
     * NOTHING IS FILTERED OUT HERE. A person entirely on leave, a person with no rotation, a person
     * with no level history, a person nobody typed a join date for and a person flagged external
     * all arrive described — deciding what any of that means is what the engine is for, and doing
     * it here would be the one catalog type this loader is forbidden to implement.
     *
     * @param  EloquentCollection<int, Person>  $people
     * @param  list<string>  $dates
     * @param  array<int, list<MasterRotaAssignment>>  $spansByPerson
     * @param  array<int, list<PersonLevel>>  $levelSpans
     * @param  array<int, list<Vacation>>  $leaveByPerson
     * @param  array<int, string>  $unitCodes
     * @param  array<string, list<string>>  $unwantedDays
     * @return list<array<string, mixed>>
     */
    private static function roster(
        EloquentCollection $people,
        array $dates,
        array $spansByPerson,
        array $levelSpans,
        array $leaveByPerson,
        array $unitCodes,
        array $unwantedDays,
    ): array {
        $out = [];

        foreach ($people as $person) {
            $id = (int) $person->getKey();
            $key = self::personKey($person);
            $joinedAt = $person->joined_at?->format(Calendar::YMD);

            $leaveDays = self::datesCoveredBy($dates, $leaveByPerson[$id] ?? []);

            $entry = [
                'key' => $key,
                'levelSpans' => self::levelSpansOf($levelSpans[$id] ?? []),
                'unitSpans' => self::unitSpansOf($spansByPerson[$id] ?? [], $unitCodes),
                'leaveDays' => $leaveDays,
                'unwantedDays' => self::suppliedDays($unwantedDays[$key] ?? []),
                'eligibleDays' => self::availableDays($dates, $leaveDays, $joinedAt),
                'external' => (bool) $person->external,
            ];

            // ABSENT, never null — the withheld-versus-empty distinction `PersonPresenter` already
            // enforces for a contact field, applied to the one optional field here. Owner decision
            // T turns a missing join date into "no finding, reported", and the engine can only tell
            // "nobody typed one" from "somebody typed one" if the two shapes differ.
            if ($joinedAt !== null) {
                $entry['joinedAt'] = $joinedAt;
            }

            $out[] = $entry;
        }

        return $out;
    }

    /**
     * @param  list<PersonLevel>  $spans
     * @return list<array<string, string>>
     */
    private static function levelSpansOf(array $spans): array
    {
        $out = [];

        foreach ($spans as $span) {
            $code = $span->level?->code;

            if ($code === null) {
                continue;
            }

            $out[] = [
                'key' => (string) $code,
                'from' => $span->effective_from->format(Calendar::YMD),
                'to' => $span->effective_to?->format(Calendar::YMD) ?? self::NO_KNOWN_END,
            ];
        }

        return $out;
    }

    /**
     * @param  list<MasterRotaAssignment>  $spans
     * @param  array<int, string>  $unitCodes
     * @return list<array<string, string>>
     */
    private static function unitSpansOf(array $spans, array $unitCodes): array
    {
        $out = [];

        foreach ($spans as $span) {
            $code = $unitCodes[(int) $span->unit_id] ?? null;

            if ($code === null) {
                continue;
            }

            $out[] = [
                'key' => $code,
                'from' => $span->starts_on->format(Calendar::YMD),
                'to' => $span->ends_on->format(Calendar::YMD),
            ];
        }

        return $out;
    }

    /**
     * The horizon dates a set of leave spans covers.
     *
     * P2 Task 1 finding 5, stated honestly: this is a FOURTH shape of the leave predicate, not a
     * fourth copy of one definition. `Vacation::scopeIntersecting()` asks it of a RANGE in SQL,
     * `RotaGrid::cellFor()` of a PERIOD in memory and `AvailabilitySummary` of a WEEK against
     * clipped bounds; nobody had "vacation versus a single DATE" because no screen needed one. The
     * bounds are inclusive at both ends, which every dated span in this schema shares, and the
     * comparison is on `Y-m-d` strings already formatted by the one converter.
     *
     * @param  list<string>  $dates
     * @param  list<Vacation>  $spans
     * @return list<string>
     */
    private static function datesCoveredBy(array $dates, array $spans): array
    {
        if ($spans === []) {
            return [];
        }

        $out = [];

        foreach ($dates as $date) {
            foreach ($spans as $span) {
                if ($date >= $span->starts_on->format(Calendar::YMD) && $date <= $span->ends_on->format(Calendar::YMD)) {
                    $out[] = $date;

                    break;
                }
            }
        }

        return $out;
    }

    /**
     * THE AVAILABILITY DENOMINATOR (owner decision J, answered 2026-08-20 against its own default).
     *
     * Days on leave come out, and so do the days before somebody started — a person who joins on
     * the 12th was not available on the 4th, and a share pro-rated over the whole block would
     * under-load them for a month they were not there for. The consequence is intended and is the
     * opposite of what a calendar denominator would give: the constraint TIGHTENS around somebody's
     * leave, which is what stops a draft back-loading them into the days either side of it.
     *
     * THE THIRD CLAUSE OF THAT DECISION IS DELIBERATELY NOT IMPLEMENTED, AND SAYING SO IS THE
     * POINT. It removes days a person is away from the department on a rotation elsewhere. There is
     * no per-person column for that anywhere in this schema (MR-04), and the only thing that looks
     * like one is the presence or absence of a rotation span — inferring it from there would make
     * the rota decide who may take call, which is the inference `RotaAccessTest` exists to refuse
     * and which owner decision I already refuses in these words for `max_gap`'s clock. A person
     * with no rotation is therefore counted as available, and this sentence is the record of that
     * being a decision rather than an omission.
     *
     * @param  list<string>  $dates
     * @param  list<string>  $leaveDays
     * @return list<string>
     */
    private static function availableDays(array $dates, array $leaveDays, ?string $joinedAt): array
    {
        $onLeave = array_flip($leaveDays);

        return array_values(array_filter(
            $dates,
            fn (string $date): bool => ! isset($onLeave[$date]) && ($joinedAt === null || $date >= $joinedAt),
        ));
    }

    /**
     * Caller-supplied dates, normalised through the one converter so a mistyped one throws here
     * rather than silently matching nothing on the far side of the contract.
     *
     * @param  list<string>  $days
     * @return list<string>
     */
    private static function suppliedDays(array $days): array
    {
        $out = array_values(array_unique(array_map(
            fn (string $day): string => Calendar::ymd($day),
            $days,
        )));

        sort($out);

        return $out;
    }

    /**
     * The clinics, with the attendee rule each one resolves through.
     *
     * The MODE and its two lists are carried because the crossing is wrong in BOTH directions
     * without them (P2 Task 1 finding 17): a clinic whose attendees are NAMED includes an external
     * consultant who rotates nowhere and would never be matched by a unit comparison, and a clinic
     * scoped to LEVELS includes only the rotators holding one of them on the date, so a plain unit
     * match over-triggers.
     *
     * The rows are the RULE, never a roster: `ClinicRoster` resolves people at read time and stores
     * nothing, because a clinic is a weekly recurrence with no date of its own and a stored roster
     * would be a snapshot as of no particular day. Nothing here calls that resolver — it returns
     * person PROJECTIONS shaped for a screen, and design §14 item 22 asks this question of the
     * clinic ROW instead.
     *
     * @param  EloquentCollection<int, Clinic>  $clinics
     * @param  array<int, string>  $unitCodes
     * @param  array<int, string>  $levelCodes
     * @return list<array<string, mixed>>
     */
    private static function clinicRules(EloquentCollection $clinics, array $unitCodes, array $levelCodes): array
    {
        $out = [];

        foreach ($clinics as $clinic) {
            $unitKey = $unitCodes[(int) $clinic->unit_id] ?? null;

            if ($unitKey === null) {
                continue;
            }

            $levelKeys = [];
            $personKeys = [];

            foreach ($clinic->attendees as $rule) {
                if ($rule->level_id !== null && isset($levelCodes[(int) $rule->level_id])) {
                    $levelKeys[] = $levelCodes[(int) $rule->level_id];
                }

                if ($rule->person_id !== null) {
                    $personKeys[] = 'p'.$rule->person_id;
                }
            }

            $out[] = [
                'key' => 'c'.$clinic->getKey(),
                'unitKey' => $unitKey,
                // `clinics.weekday` is a plain ISO 1-7 already; the engine takes ISO integers and
                // refuses a day NAME, which is what keeps the week's vocabulary in one place.
                'isoWeekday' => (int) $clinic->weekday,
                'session' => (string) $clinic->session,
                'active' => (bool) $clinic->active,
                'attendeeMode' => (string) $clinic->attendee_mode,
                'attendeeLevelKeys' => $levelKeys,
                'attendeePersonKeys' => $personKeys,
            ];
        }

        return $out;
    }
}
