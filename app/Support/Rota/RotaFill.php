<?php

namespace App\Support\Rota;

use App\Models\MasterRotaAssignment;
use App\Models\Period;
use App\Models\Person;
use App\Models\Unit;
use App\Support\Calendar;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;

/**
 * Munawib MR-06's bulk moves — fill-down, fill-across and copy-period — as **the plan a bulk move
 * makes, before it makes it** (P1d-2 Decisions E and F).
 *
 * THIS CLASS WRITES NOTHING. `plan()` computes a delta and returns it; not one row is created,
 * updated or deleted anywhere in this file, and `RotaFillPlanTest::test_plan_writes_nothing`
 * pins that over a fully populated year by comparing the whole `master_rota_assignments` row set
 * — dates included — before and after a plan naming hundreds of target cells. Task 8's `apply()`
 * is the only thing that writes, it goes through `RotaAssignment` (never `MasterRotaAssignment`
 * directly — `RotaWritersAreSingularTest` fails the build for a second writer), and it re-runs the
 * same private `analyse()` INSIDE its own transaction rather than trusting anything a client sends
 * back: the rota can change between the preview and the confirm, exactly as `RosterImport::commit()`
 * re-derives rather than trusting a round trip.
 *
 * Separating the two is what makes "validate and authorize the whole set before any mutation"
 * (P1 finding 12, `AccessControlController::updateRoles()`) structurally true rather than a comment
 * somebody has to keep honouring.
 *
 * THE FOUR OPERATIONS. Three shapes, four actions, because **fill-down is offered as two explicit
 * actions rather than one that guesses which the operator meant** — a single control that infers
 * "this level group" from "this whole column" is wrong half the time on the most destructive
 * surface in the rota.
 *
 *  - `FILL_DOWN_LEVEL`  — same period, every other person whose level AT THAT PERIOD matches the
 *                         source cell's.
 *  - `FILL_DOWN_COLUMN` — same period, every other person in the column.
 *  - `FILL_ACROSS`      — same person, **forwards only**: every period of the same academic year
 *                         after the source's `position`. Backwards would overwrite periods the
 *                         department has already worked, and MR-06 does not ask for it.
 *  - `COPY_PERIOD`      — one whole column onto another period's whole column.
 *
 * DECISION E — WHY A SPLIT DOES NOT CROSS A PERIOD BOUNDARY. Every assignment is a date-bounded
 * span and every span must lie inside its own period (`MasterRotaAssignment::booted()` refuses
 * otherwise, and `RotaAssignment::split()` refuses before it). Periods are NOT the same length —
 * MR-01 lets block lengths vary within a year, and a calendar-month system has 28-to-31-day periods
 * by construction. So there is no correct way to map "PICU for the first nine days, NICU for the
 * rest" onto a period of another length: rescaling invents dates nobody chose and clamping silently
 * truncates one of the two units. Therefore:
 *
 *  - **Fill-down copies the span set VERBATIM, splits included** — the target period *is* the
 *    source period, so the dates are already inside it by construction.
 *  - **Fill-across and copy-period accept a WHOLE-PERIOD source only**, and land it on the
 *    **target's own bounds**. A split (or a partial) source is reported `SKIP_SPLIT_SOURCE` with
 *    the reason named, and the operator splits the target cell by hand. Naming it in the preview is
 *    what makes it a decision the operator sees rather than a behaviour they discover when Block 12
 *    looks wrong.
 *
 * A TARGET CELL CARRYING A SPLIT IS SKIPPED UNLESS THAT CELL IS EXPLICITLY CONFIRMED, AND THAT
 * DEFAULT IS A DATA-LOSS GUARD, NOT A CONVENIENCE. A blanket fill silently destroying deliberate
 * split work is the exact class this codebase keeps guarding against (the legacy toolbar's global
 * `styleWithCSS`, the audit chain's second canonical string, `RotaAssignment::split()` validating
 * the whole set before deleting the old one). The confirmation is PER TARGET CELL, keyed
 * `"<personId>:<periodId>"`, absent meaning false — the same shape `RosterImport`'s
 * `$confirmations` already has, for the same reason: a destructive default must be opt-in, per
 * item, and visible in the request body the operator's own preview was rendered from.
 *
 * WHAT COUNTS AS A SPLIT, stated because the narrow reading is a live bug. A cell carries a split
 * when its span set is anything other than empty or exactly one span covering the whole period —
 * so a SINGLE span that starts on the 9th (Decision E's own example, the resident who joins the
 * block late) is protected too. Counting spans instead would let a fill silently erase that start
 * date, which is precisely the work the guard exists to keep.
 *
 * A STALE PERSON IS NEVER FILLED INTO. People are deactivated, never deleted, so "assigned in
 * March, left in April" is a state the system reaches on its own; P1d-1's pre-merge review made
 * those rows visible and clearable in the editor but not assignable, and `RotaCellRequest` applies
 * the strict active predicate on its two WRITE routes and a bare exists on DELETE for exactly that
 * reason (finding 10). A fill is a write, so the strict half applies: a deactivated or soft-deleted
 * target person is ENUMERATED as a target and skipped `SKIP_STALE_PERSON`. Enumerated rather than
 * quietly dropped, because the operator can see that row on the grid and a preview whose count is
 * mysteriously lower than the column is a preview they cannot check. A retired source unit is
 * refused on the same rule, `SKIP_RETIRED_UNIT`: a unit stays visible on the historical span it was
 * written under, and is never assigned to a NEW cell.
 *
 * THE PLAN IS STABLE AND INSPECTABLE. The same inputs produce the same delta — targets are ordered
 * deterministically (by person name then id for a column, by period position for a fill-across) —
 * and every target names its outcome, the reason for it when it is a skip, its CURRENT span set and
 * the PROPOSED one, so the preview renders both without a second computation and `apply()` needs no
 * second pass.
 *
 * IT NAMES NOBODY. Ids, dates and counts only — no person name, no unit code, no period label. Both
 * halves of this feature (the preview props and Task 8's single `rota_fill` audit row) inherit that
 * from here rather than each remembering it.
 *
 * THE QUERIES ARE BOUNDED, and constant in the number of targets: the source cell, the target
 * periods, the target people, their level history and the active-unit set are each resolved in ONE
 * query and grouped in PHP — the discipline `RotaGrid` documents by name. A per-target
 * `MasterRotaAssignment::where(...)` lookup is a 780-query fill preview, which is the N+1 class this
 * codebase has already paid for once in `Person::levelAt()`-per-cell.
 *
 * THIS IS NOT AN ON-CALL ELIGIBILITY COMPUTATION AND MUST NEVER BECOME ONE. MR-04 is Stage 2.
 * Nothing here infers who may take call; a fill moves planned assignments and nothing else.
 */
final class RotaFill
{
    /** Same period, the source cell's level group only. */
    public const FILL_DOWN_LEVEL = 'fill_down_level';

    /** Same period, everybody else in the column. */
    public const FILL_DOWN_COLUMN = 'fill_down_column';

    /** Same person, forwards to every later period of the academic year. */
    public const FILL_ACROSS = 'fill_across';

    /** One whole column onto another period's whole column. */
    public const COPY_PERIOD = 'copy_period';

    /** @var list<string> */
    public const OPERATIONS = [
        self::FILL_DOWN_LEVEL,
        self::FILL_DOWN_COLUMN,
        self::FILL_ACROSS,
        self::COPY_PERIOD,
    ];

    /** The target cell was empty. */
    public const ASSIGN = 'assign';

    /** The target held something else, and it is being overwritten. */
    public const REPLACE = 'replace';

    /** The target already holds exactly this — not written, and not counted as a change. */
    public const UNCHANGED = 'unchanged';

    /** The target carries a split and the operator has not confirmed THIS cell. */
    public const SKIP_SPLIT_TARGET = 'skip_split_target';

    /** Fill-across / copy-period from a split (or partial) source — Decision E. */
    public const SKIP_SPLIT_SOURCE = 'skip_split_source';

    /** The target person is off the active roster (finding 10 — offer/write parity). */
    public const SKIP_STALE_PERSON = 'skip_stale_person';

    /** The source assignment names a unit that is no longer active (same rule). */
    public const SKIP_RETIRED_UNIT = 'skip_retired_unit';

    /**
     * Why each skip happened, in the words the preview shows the operator. A skip whose reason the
     * screen has to invent is a skip the operator cannot act on.
     *
     * @var array<string, string>
     */
    private const REASONS = [
        self::SKIP_SPLIT_SOURCE => 'Periods differ in length, so a split cannot be copied onto another '
            .'period without inventing dates or truncating a unit. Only a whole-period assignment '
            .'fills across — split the target cell by hand.',
        self::SKIP_SPLIT_TARGET => 'This cell already carries a split. Filling over it would discard '
            .'dates somebody chose deliberately, so it is skipped unless you confirm this cell.',
        self::SKIP_STALE_PERSON => 'This person is no longer on the active roster. A fill never assigns '
            .'to somebody who has left — clear their cells in the editor instead.',
        self::SKIP_RETIRED_UNIT => 'The source assignment names a unit that has been retired. A retired '
            .'unit stays on the spans already written under it, but is never assigned to a new cell.',
    ];

    /**
     * The delta a bulk move WOULD produce. Writes nothing.
     *
     * @param  array{person_id?:int|null, period_id?:int|null, target_period_id?:int|null}  $source
     *                                                                                              the source cell (or, for `COPY_PERIOD`, the source and target columns)
     * @param  array<string, bool>  $confirmations  target-cell key (`"<personId>:<periodId>"`) => the
     *                                              operator confirmed overwriting a SPLIT in that
     *                                              cell. Absent means false. Shaped after
     *                                              `RosterImport`'s `$confirmations` for the same
     *                                              reason: a destructive default must be opt-in, per
     *                                              item, and visible in the request body.
     * @return array{
     *     op: string,
     *     source: array{person_id: int|null, period_id: int|null, target_period_id: int|null},
     *     targets: list<array{person_id:int, period_id:int, key:string, outcome:string, reason:string|null, current:list<array{unit_id:int,starts_on:string,ends_on:string}>, proposed:list<array{unit_id:int,starts_on:string,ends_on:string}>}>,
     *     summary: array{assign:int, replace:int, unchanged:int, skipped:int},
     *     errors: list<string>,
     * }
     */
    public static function plan(string $op, array $source, array $confirmations): array
    {
        $analysis = self::analyse($op, $source, $confirmations);

        // `context` carries the resolved Person/Period models Task 8's `apply()` dispatches to
        // `RotaAssignment` without a second round of queries. It never reaches a screen: these are
        // Eloquent models, and a props payload built from whole models is how a contact field
        // reaches a page nobody meant to put it on (P1d-2 Decision C, finding 3).
        unset($analysis['context']);

        return $analysis;
    }

    /**
     * The ONE analysis. `plan()` is this with the writes not taken; Task 8's `apply()` calls it
     * again inside its own transaction and acts on what it says. Two implementations of "what would
     * this fill do" is two answers, and the operator confirmed one of them.
     *
     * @param  array{person_id?:int|null, period_id?:int|null, target_period_id?:int|null}  $source
     * @param  array<string, bool>  $confirmations
     * @return array<string, mixed>
     */
    private static function analyse(string $op, array $source, array $confirmations): array
    {
        $sourcePersonId = isset($source['person_id']) ? (int) $source['person_id'] : null;
        $sourcePeriodId = isset($source['period_id']) ? (int) $source['period_id'] : null;
        $targetPeriodId = isset($source['target_period_id']) ? (int) $source['target_period_id'] : null;

        $out = [
            'op' => $op,
            'source' => [
                'person_id' => $sourcePersonId,
                'period_id' => $sourcePeriodId,
                'target_period_id' => $targetPeriodId,
            ],
            'targets' => [],
            'summary' => ['assign' => 0, 'replace' => 0, 'unchanged' => 0, 'skipped' => 0],
            'errors' => [],
            'context' => ['people' => [], 'periods' => []],
        ];

        if (! in_array($op, self::OPERATIONS, true)) {
            $out['errors'][] = 'Unknown fill operation.';

            return $out;
        }

        // Query 1 — the source column.
        $sourcePeriod = $sourcePeriodId === null ? null : Period::find($sourcePeriodId);

        if ($sourcePeriod === null) {
            $out['errors'][] = 'The period this fill starts from no longer exists.';

            return $out;
        }

        $crossPeriod = $op === self::FILL_ACROSS || $op === self::COPY_PERIOD;

        // Query 2 (cross-period operations only) — the academic year's columns. Bounded by the
        // year, never by the number of targets, and it is what makes "forwards only" a comparison
        // rather than a second query per candidate period.
        $targetPeriods = [];

        if ($op === self::FILL_ACROSS) {
            $targetPeriods = Period::query()
                ->forYear($sourcePeriod->academic_year)
                ->where('position', '>', $sourcePeriod->position)
                ->ordered()
                ->get()
                ->all();
        } elseif ($op === self::COPY_PERIOD) {
            if ($targetPeriodId === null || $targetPeriodId === $sourcePeriod->getKey()) {
                $out['errors'][] = 'Choose a different period to copy this one onto.';

                return $out;
            }

            $target = Period::query()
                ->forYear($sourcePeriod->academic_year)
                ->whereKey($targetPeriodId)
                ->first();

            if ($target === null) {
                $out['errors'][] = 'The period being copied onto is not part of this academic year.';

                return $out;
            }

            $targetPeriods = [$target];
        }

        // Query 3 — every span in play, in one shot: the source cell(s) and every target cell.
        // Narrowed to one person for a fill-across, where every target is that same person.
        $periodIds = array_merge(
            [$sourcePeriod->getKey()],
            array_map(static fn (Period $period): int => (int) $period->getKey(), $targetPeriods),
        );

        $assignments = MasterRotaAssignment::query()
            ->whereIn('period_id', $periodIds)
            ->when($op === self::FILL_ACROSS, fn ($query) => $query->where('person_id', $sourcePersonId))
            ->orderBy('starts_on')
            ->get();

        $spansByPersonPeriod = [];

        foreach ($assignments as $assignment) {
            $spansByPersonPeriod[(int) $assignment->person_id][(int) $assignment->period_id][] = $assignment;
        }

        // Query 4 — the active-unit set. Ids only: this is a membership test, not a projection, and
        // a fill never renders a unit's name.
        $activeUnitIds = array_map('intval', Unit::query()->active()->pluck('id')->all());

        // Query 5 — the target people. ONE query per operation, never one per target.
        [$people, $peopleError] = self::targetPeople($op, $sourcePeriod, $sourcePersonId, $spansByPersonPeriod);

        if ($peopleError !== null) {
            $out['errors'][] = $peopleError;

            return $out;
        }

        // Query 6 (FILL_DOWN_LEVEL only) — the level history intersecting the source period's start
        // for the whole candidate set at once. `Person::levelAt()` per candidate is the per-cell
        // N+1 `RotaGrid` documents; `levelFromSpans()` resolves each one in memory from this.
        $levelByPerson = [];

        if ($op === self::FILL_DOWN_LEVEL) {
            $on = $sourcePeriod->starts_on->format(Calendar::YMD);
            $levelSpans = Person::levelSpansBetween($people, $on, $on);

            foreach ($people as $person) {
                $id = (int) $person->getKey();
                $levelByPerson[$id] = Person::levelFromSpans($levelSpans[$id] ?? [], $on)?->getKey();
            }
        }

        // ---- the delta itself: pure, from what is already in memory.

        $sourceCells = [];   // person id => the span tuples being copied FROM

        if ($op === self::COPY_PERIOD) {
            // Every person holding something in the source column has their own source cell, so
            // SKIP_SPLIT_SOURCE is decided per target here, not once for the operation.
            foreach ($people as $person) {
                $tuples = self::tuples($spansByPersonPeriod, (int) $person->getKey(), (int) $sourcePeriod->getKey());

                if ($tuples !== []) {
                    $sourceCells[(int) $person->getKey()] = $tuples;
                }
            }

            if ($sourceCells === []) {
                $out['errors'][] = 'That period has no assignments to copy.';

                return $out;
            }
        } else {
            $tuples = self::tuples($spansByPersonPeriod, (int) $sourcePersonId, (int) $sourcePeriod->getKey());

            if ($tuples === []) {
                $out['errors'][] = 'That cell is empty — there is nothing to fill from. '
                    .'Assign the source cell first, then fill.';

                return $out;
            }

            $sourceCells[(int) $sourcePersonId] = $tuples;
        }

        $peopleById = [];

        foreach ($people as $person) {
            $peopleById[(int) $person->getKey()] = $person;
        }

        $periodsById = [(int) $sourcePeriod->getKey() => $sourcePeriod];

        foreach ($targetPeriods as $period) {
            $periodsById[(int) $period->getKey()] = $period;
        }

        foreach (self::targetCells($op, $sourcePeriod, $targetPeriods, $sourcePersonId, $people, $levelByPerson) as [$personId, $periodId]) {
            $targetPeriod = $periodsById[$periodId];
            $sourceTuples = $sourceCells[$op === self::COPY_PERIOD ? $personId : (int) $sourcePersonId] ?? [];

            if ($sourceTuples === []) {
                // COPY_PERIOD only: nothing in the source cell, so this person is not a target at
                // all. A fill NEVER clears — an empty source means "leave it alone", never
                // "empty the target too".
                continue;
            }

            [$proposed, $sourceRefusal] = self::project($sourceTuples, $sourcePeriod, $targetPeriod, $activeUnitIds);
            $current = self::tuples($spansByPersonPeriod, $personId, $periodId);
            $key = $personId.':'.$periodId;

            [$outcome, $shown] = self::outcomeFor(
                $sourceRefusal,
                $peopleById[$personId] ?? null,
                $current,
                $proposed,
                $targetPeriod,
                ($confirmations[$key] ?? false) === true,
            );

            $out['targets'][] = [
                'person_id' => $personId,
                'period_id' => $periodId,
                'key' => $key,
                'outcome' => $outcome,
                'reason' => self::REASONS[$outcome] ?? null,
                'current' => $current,
                'proposed' => $shown,
            ];

            $out['summary'][str_starts_with($outcome, 'skip_') ? 'skipped' : $outcome]++;
        }

        if ($out['targets'] === []) {
            $out['errors'][] = 'There is nothing for this fill to do — no other cell to fill into.';

            return $out;
        }

        $out['context'] = ['people' => $peopleById, 'periods' => $periodsById];

        return $out;
    }

    /**
     * The candidate people, in ONE query per operation.
     *
     * A fill-down's candidate set mirrors `RotaGrid`'s row set — the active roster UNIONED with
     * anybody still holding a span in this period — so that a stale row the operator can see on the
     * grid is a row the preview accounts for and skips by name, rather than one that silently is
     * not there.
     *
     * @param  array<int, array<int, list<MasterRotaAssignment>>>  $spansByPersonPeriod
     * @return array{0: EloquentCollection<int, Person>, 1: string|null}
     */
    private static function targetPeople(string $op, Period $sourcePeriod, ?int $sourcePersonId, array $spansByPersonPeriod): array
    {
        if ($op === self::FILL_ACROSS) {
            if ($sourcePersonId === null) {
                return [new EloquentCollection, 'This fill needs a person to fill across for.'];
            }

            $person = Person::query()->withTrashed()->whereKey($sourcePersonId)->get();

            return $person->isEmpty()
                ? [new EloquentCollection, 'That person is no longer on the roster.']
                : [$person, null];
        }

        if ($op === self::COPY_PERIOD) {
            $ids = [];

            foreach ($spansByPersonPeriod as $personId => $byPeriod) {
                if (isset($byPeriod[(int) $sourcePeriod->getKey()])) {
                    $ids[] = (int) $personId;
                }
            }

            return [
                $ids === []
                    ? new EloquentCollection
                    : Person::query()->withTrashed()
                        ->whereIn('people.id', $ids)
                        ->orderBy('people.full_name')
                        ->orderBy('people.id')
                        ->get(),
                null,
            ];
        }

        if ($sourcePersonId === null) {
            return [new EloquentCollection, 'This fill needs a source cell to fill from.'];
        }

        // The union, as ONE query: `withTrashed()` drops the soft-delete scope, so the "active"
        // branch has to re-state `deleted_at IS NULL` itself — the same shape `RotaGrid` builds in
        // two queries, collapsed here into one with a bound subquery (never concatenated SQL).
        //
        // The stale half is scoped to the whole ACADEMIC YEAR, not to this period, because that is
        // `RotaGrid`'s row set and therefore the column the operator is looking at: a person who
        // left in April still has a row in every column of that year, including the ones where
        // their cell is empty. Scoping it to the source period instead was the first shape written
        // here, and `test_an_inactive_target_person_is_skipped` caught it — the departed person
        // simply was not in the plan, so the preview was one row shorter than the column with
        // nothing to say why.
        return [
            Person::query()->withTrashed()
                ->where(function ($query) use ($sourcePeriod): void {
                    $query
                        ->where(fn ($active) => $active->where('people.active', true)->whereNull('people.deleted_at'))
                        // Both halves are MODEL query builders, never a raw table name: `from(
                        // 'master_rota_assignments')` would be the first mention of that table
                        // outside its one writer, and `RotaWritersAreSingularTest`'s needles do not
                        // look for it. D11: scoped by `academic_year`, never `institution_id`.
                        ->orWhereIn('people.id', MasterRotaAssignment::query()
                            ->select('person_id')
                            ->whereIn('period_id', Period::query()
                                ->forYear($sourcePeriod->academic_year)
                                ->select('id')));
                })
                ->orderBy('people.full_name')
                ->orderBy('people.id')
                ->get(),
            null,
        ];
    }

    /**
     * Which (person, period) cells this operation touches, in a DETERMINISTIC order — the same
     * inputs must produce the same delta, because the plan an operator confirms has to be the plan
     * that runs.
     *
     * The source cell itself is never a target.
     *
     * @param  list<Period>  $targetPeriods
     * @param  EloquentCollection<int, Person>  $people
     * @param  array<int, int|null>  $levelByPerson
     * @return list<array{0:int, 1:int}>
     */
    private static function targetCells(
        string $op,
        Period $sourcePeriod,
        array $targetPeriods,
        ?int $sourcePersonId,
        EloquentCollection $people,
        array $levelByPerson,
    ): array {
        $cells = [];

        if ($op === self::FILL_ACROSS) {
            foreach ($targetPeriods as $period) {
                $cells[] = [(int) $sourcePersonId, (int) $period->getKey()];
            }

            return $cells;
        }

        if ($op === self::COPY_PERIOD) {
            $targetPeriodId = (int) $targetPeriods[0]->getKey();

            foreach ($people as $person) {
                $cells[] = [(int) $person->getKey(), $targetPeriodId];
            }

            return $cells;
        }

        // A person with no level history at all is their own group (null), the same choice
        // `AvailabilitySummary::NO_LEVEL` makes: dropping them would hide them from the one screen
        // that exists to show where everybody is.
        $sourceLevel = $levelByPerson[(int) $sourcePersonId] ?? null;
        $periodId = (int) $sourcePeriod->getKey();

        foreach ($people as $person) {
            $personId = (int) $person->getKey();

            if ($personId === $sourcePersonId) {
                continue;
            }

            if ($op === self::FILL_DOWN_LEVEL && ($levelByPerson[$personId] ?? null) !== $sourceLevel) {
                continue;
            }

            $cells[] = [$personId, $periodId];
        }

        return $cells;
    }

    /**
     * What the source cell becomes on a given target period, and the source-level refusal if it
     * cannot become anything (Decision E).
     *
     * @param  list<array{unit_id:int, starts_on:string, ends_on:string}>  $sourceTuples
     * @param  list<int>  $activeUnitIds
     * @return array{0: list<array{unit_id:int, starts_on:string, ends_on:string}>, 1: string|null}
     */
    private static function project(array $sourceTuples, Period $sourcePeriod, Period $targetPeriod, array $activeUnitIds): array
    {
        foreach ($sourceTuples as $tuple) {
            if (! in_array($tuple['unit_id'], $activeUnitIds, true)) {
                return [[], self::SKIP_RETIRED_UNIT];
            }
        }

        // Same period: the dates are already inside it, so the split copies verbatim.
        if ((int) $sourcePeriod->getKey() === (int) $targetPeriod->getKey()) {
            return [$sourceTuples, null];
        }

        if (! self::isWholePeriod($sourceTuples, $sourcePeriod)) {
            return [[], self::SKIP_SPLIT_SOURCE];
        }

        return [[[
            'unit_id' => $sourceTuples[0]['unit_id'],
            'starts_on' => $targetPeriod->starts_on->format(Calendar::YMD),
            'ends_on' => $targetPeriod->ends_on->format(Calendar::YMD),
        ]], null];
    }

    /**
     * The outcome for one target cell, and the span set the preview shows beside it.
     *
     * ORDER MATTERS, and each step is here for a reason:
     *
     *  1. A SOURCE-level refusal comes first. It kills every target, so a preview in which one row
     *     blames the target and the rest blame the source would read as a partial failure when it is
     *     a total one.
     *  2. A stale target person cannot be assigned to at all (finding 10).
     *  3. UNCHANGED is decided BEFORE the split guard. A target already holding exactly the proposed
     *     set loses nothing, so demanding a confirmation for it would be a tick for a no-op — and an
     *     operator taught to tick meaningless boxes is an operator who ticks the meaningful one.
     *  4. Only then the split guard, defaulting to skip.
     *
     * A skip carries NO proposal — except `SKIP_SPLIT_TARGET`, which keeps it, because that is the
     * one skip the operator is being asked to overrule and they cannot choose between two span sets
     * they can only see one of.
     *
     * @param  list<array{unit_id:int, starts_on:string, ends_on:string}>  $current
     * @param  list<array{unit_id:int, starts_on:string, ends_on:string}>  $proposed
     * @return array{0: string, 1: list<array{unit_id:int, starts_on:string, ends_on:string}>}
     */
    private static function outcomeFor(
        ?string $sourceRefusal,
        ?Person $person,
        array $current,
        array $proposed,
        Period $targetPeriod,
        bool $confirmed,
    ): array {
        if ($sourceRefusal !== null) {
            return [$sourceRefusal, []];
        }

        if ($person === null || ! $person->active || $person->trashed()) {
            return [self::SKIP_STALE_PERSON, []];
        }

        if ($current === $proposed) {
            return [self::UNCHANGED, $proposed];
        }

        if (! $confirmed && self::isSplit($current, $targetPeriod)) {
            return [self::SKIP_SPLIT_TARGET, $proposed];
        }

        return [$current === [] ? self::ASSIGN : self::REPLACE, $proposed];
    }

    /**
     * A cell's span set as plain comparable tuples, oldest first. Comparison is by `Y-m-d` STRING,
     * a format that sorts and equates correctly as text — no date arithmetic anywhere in this class
     * (ST-06: `App\Support\Calendar` is the only converter, and the only thing borrowed from it here
     * is its format constant).
     *
     * @param  array<int, array<int, list<MasterRotaAssignment>>>  $spansByPersonPeriod
     * @return list<array{unit_id:int, starts_on:string, ends_on:string}>
     */
    private static function tuples(array $spansByPersonPeriod, int $personId, int $periodId): array
    {
        $out = [];

        foreach ($spansByPersonPeriod[$personId][$periodId] ?? [] as $assignment) {
            $out[] = [
                'unit_id' => (int) $assignment->unit_id,
                'starts_on' => $assignment->starts_on->format(Calendar::YMD),
                'ends_on' => $assignment->ends_on->format(Calendar::YMD),
            ];
        }

        usort($out, static fn (array $a, array $b): int => $a['starts_on'] <=> $b['starts_on']);

        return $out;
    }

    /**
     * Exactly one span, covering the period end to end — the degenerate split
     * (`MasterRotaAssignment`'s own docblock), and the only shape that crosses a period boundary.
     *
     * @param  list<array{unit_id:int, starts_on:string, ends_on:string}>  $tuples
     */
    private static function isWholePeriod(array $tuples, Period $period): bool
    {
        return count($tuples) === 1
            && $tuples[0]['starts_on'] === $period->starts_on->format(Calendar::YMD)
            && $tuples[0]['ends_on'] === $period->ends_on->format(Calendar::YMD);
    }

    /**
     * Anything other than empty or one whole-period span — TWO spans, or one that starts late or
     * ends early. The partial is deliberately included: "starts on the 9th" is a date somebody
     * chose, and a fill that erased it would be the silent data loss the confirmation exists for.
     *
     * @param  list<array{unit_id:int, starts_on:string, ends_on:string}>  $tuples
     */
    private static function isSplit(array $tuples, Period $period): bool
    {
        return $tuples !== [] && ! self::isWholePeriod($tuples, $period);
    }
}
