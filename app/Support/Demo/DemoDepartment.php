<?php

namespace App\Support\Demo;

use App\Models\AuditLog;
use App\Models\Clinic;
use App\Models\Institution;
use App\Models\Level;
use App\Models\MasterRotaAssignment;
use App\Models\Period;
use App\Models\Person;
use App\Models\PersonLevel;
use App\Models\Unit;
use App\Support\Calendar;
use App\Support\Clinics\ClinicWriter;
use App\Support\LevelAssignment;
use App\Support\Rota\RotaAssignment;
use App\Support\Rota\VacationBooking;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use InvalidArgumentException;

/**
 * Munawib ST-05's *"one-click, clearly-labeled, REMOVABLE demo department"* (P1e Decision F).
 *
 * ---------------------------------------------------------------------------------------------
 * WHY THIS ONE MAY RUN WHERE `DemoSeeder` MAY NOT
 * ---------------------------------------------------------------------------------------------
 * `DemoSeeder` and `E2eSeeder` both throw outright in production, and both are right to: neither
 * marks a single row it writes, so a row either creates is indistinguishable from a real one
 * forever, and neither has a removal path of any kind. This class carries NO environment guard,
 * on the owner's ruling of 2026-08-11, because two properties make it safe in the place those two
 * are not — and both are asserted rather than asserted about:
 *
 *   1. EVERY ROW IS LEDGERED, through `DemoLedger`, inside the same transaction that creates it.
 *      Removal walks that ledger, and `DemoRoundTripTest` proves the walk is complete by
 *      snapshotting every table in the live schema around a create-and-remove.
 *   2. IT MINTS NO ACCOUNT. Not one `users` row. A roster-only person has no account and therefore
 *      CANNOT AUTHENTICATE BY CONSTRUCTION (P0c) — so pressing this on a live instance holding
 *      children's PHI creates no working login, which is the specific harm `DemoSeeder`'s throw
 *      exists to prevent. That is a design constraint of this class, not an omission: adding an
 *      account here would put a demo credential into a clinical system and would need an
 *      environment guard back, at which point ST-05's "for training" (which means the live
 *      instance, because that is where people are trained) could not be met at all.
 *
 * `DemoSeeder` is NOT modified, NOT replaced and keeps its throw. Consolidating the two is a
 * follow-on recorded in the design doc, not smuggled in here.
 *
 * ---------------------------------------------------------------------------------------------
 * IT GOES THROUGH THE WRITERS, NEVER AROUND THEM
 * ---------------------------------------------------------------------------------------------
 * `ClinicWriter` for the clinic and its attendee rules, `RotaAssignment` for the master rota,
 * `VacationBooking` for the leave, `LevelAssignment` for the level history. Two reasons, and the
 * second is the one that matters: the single-writer build guards would fail on a second writer, and
 * — decisively — every one of those writers refuses states the database cannot express. A demo
 * built around them could create a department the product itself cannot represent, which is worse
 * than no demo at all, because the first thing it teaches is wrong.
 *
 * The two rows no writer owns are the demo UNIT and the demo PEOPLE. `units` and `people` have no
 * single-writer class in this codebase (their integrity lives in `Unit::booted()`'s reserved-code
 * guard and in `PersonStatus` for the one column that needed one), so they are created here
 * directly, in the same idiom `ReferenceSeeder` and `E2eSeeder` already use.
 *
 * ---------------------------------------------------------------------------------------------
 * THE HUMAN LABEL AND THE MACHINE TRUTH ARE DIFFERENT JOBS
 * ---------------------------------------------------------------------------------------------
 * Every name this creates begins {@see LABEL_PREFIX} and every address sits on {@see EMAIL_DOMAIN}
 * — a domain RFC 2606 reserves and the DNS root guarantees can never resolve, so an invitation
 * issued to a demo person by mistake cannot reach a real inbox. That label survives into a CSV
 * export and onto a printed sheet, where no ledger lookup happens. The ledger survives a rename,
 * which the label does not. NEITHER SUBSTITUTES FOR THE OTHER, and both exist deliberately.
 *
 * The fixture is SYNTHETIC AND STAYS THAT WAY (CLAUDE.md). It ships inside the product rather than
 * beside the tests, which makes it the most visible fixture in this repository; no real staff
 * member's name, address or number belongs in it at any time.
 *
 * ---------------------------------------------------------------------------------------------
 * ONE TRANSACTION, ONE AUDIT ROW
 * ---------------------------------------------------------------------------------------------
 * A demo department is a bulk operation by invariant 12's definition: it validates the whole set
 * before mutating, runs in one transaction, refuses whole rather than partially, and writes ONE
 * summary audit row — never one per row. `AuditLog::record()` locks the chain tail inside its own
 * transaction, so N of them nested in a loop serialise the entire trail and unwind it on rollback
 * (P1 finding 11). The row is written AFTER the transaction commits, carrying ids and counts only:
 * a batch id and a row count, never a name (invariant 9).
 *
 * IT READS THE INSTITUTION ROW when the caller does not supply one, which is why it sits on
 * `CalendarWritersFlushTest`'s allow-list: `institution_id` is D11 provenance, stamped on rows and
 * never used to find one, and it has nothing to do with the calendar memo that guard protects.
 * `ClinicWriter` takes the opposite branch (caller-supplied only) because it runs solely inside a
 * request, where an actor always exists; this one is also driven from the console, where there is
 * no actor to take it from and a second definition of "the current institution" is the alternative.
 */
final class DemoDepartment
{
    /** Every human-visible name this creates begins with it. ST-05's "clearly-labeled". */
    public const LABEL_PREFIX = 'Demo ';

    /**
     * Reserved by RFC 2606 and guaranteed never to resolve, unlike `example.org` (which resolves
     * to a real, IANA-operated host). Mail addressed here cannot be delivered anywhere, which is
     * the property that matters on a LIVE instance.
     */
    public const EMAIL_DOMAIN = 'demo.invalid';

    /**
     * Short, obvious, and NOT in `Unit::RESERVED_CODES` (`TODAY`, `COMPLIANCE`, `ROWS` — derived
     * from the registered routes under /endorsement, and enforced by a `saving` guard that throws).
     * `DemoCreateTest` asserts the non-collision rather than trusting this comment, because that
     * list grows whenever a literal route segment is added.
     */
    public const UNIT_CODE = 'DEMO';

    /** A colour from `Unit::BAR_CLASSES`, distinct from all four seeded units and from the default. */
    public const UNIT_BAR_CLASS = 'channel-bar-clay';

    /** The academic year the demo generates under, when it generates one at all. */
    public const ACADEMIC_YEAR = 'Demo';

    /**
     * The people, in creation order: `[full name, short name, position]`.
     *
     * Positions 3 / 4 / 5 are Consultant / Resident / Chief Resident — the three roles a handover
     * is actually attested between (design §5.1), so the sign-off pickers have something to offer
     * the moment somebody opens a sheet. Position 0 (Administrator) is deliberately absent: the
     * demo names nobody who could be mistaken for a real account holder, and it mints no account
     * for anybody at all.
     *
     * Obviously fictional by construction. `people.short_name` carries its own UNIQUE index, so
     * these are prefixed too rather than left to collide with a real abbreviation.
     */
    private const PEOPLE = [
        ['Demo Consultant One', 'Demo-C1', 3],
        ['Demo Chief Resident One', 'Demo-CR1', 5],
        ['Demo Resident One', 'Demo-R1', 4],
        ['Demo Resident Two', 'Demo-R2', 4],
        ['Demo Resident Three', 'Demo-R3', 4],
    ];

    /**
     * Build the demo department. One transaction, one batch, every row ledgered.
     *
     * Returns `{batch, rows, skipped}` rather than the bare batch id the plan sketched: the period
     * step legitimately SKIPS on a department that has already generated its academic year, and a
     * skip nobody is told about is a demo that quietly differs from the one the last person saw.
     *
     * The key is `skipped` and not the obvious word for it, deliberately and for a reason worth
     * recording: that word is also a `people` column, and `ContactFieldsAreProjectedOnceTest`
     * scans for it as a quoted array key. The key TRAVELS — into this class, into the console
     * command, into the controller — so an allow-list entry per consumer would blind three files
     * to a real contact-field guard for the sake of a name. Renaming costs one word.
     *
     * @param  int|null  $institutionId  D11 provenance. Resolved from the single active institution
     *                                   row when absent — never a filter, never a key.
     * @param  int|null  $actorId        The operator, for the audit row. Absent from the console.
     * @return array{batch: string, rows: int, skipped: list<string>}
     *
     * @throws InvalidArgumentException when a demo department already exists, or the demo unit
     *                                  code is already taken by a real unit
     */
    public static function create(?int $institutionId = null, ?int $actorId = null, ?string $ip = null): array
    {
        // REFUSE BEFORE WRITING, always — `ClinicWriter`'s discipline, and the reason a refusal is
        // never a half-built department. Both checks below are read-only and both name the remedy.
        if (DemoLedger::has()) {
            throw new InvalidArgumentException(
                'A demo department already exists. Remove it first, then create a new one — two of '
                .'them cannot be told apart on screen, and only one can hold the demo unit code.'
            );
        }

        if (Unit::findByCode(self::UNIT_CODE) !== null) {
            throw new InvalidArgumentException(
                'A unit with the code ['.self::UNIT_CODE.'] already exists and is not part of any '
                .'demo department. Rename or retire it before creating the demo.'
            );
        }

        $institutionId ??= Institution::current()?->getKey();

        $batch = (string) Str::uuid();
        $skipped = [];

        DB::transaction(function () use ($batch, $institutionId, &$skipped): void {
            $unit = self::unit();
            DemoLedger::record('units', (int) $unit->getKey(), $batch);

            $people = self::people($institutionId, $batch);

            $levels = self::ladder();

            if ($levels === []) {
                $skipped[] = 'No active training level exists yet, so the demo people carry no level '
                    .'history and the demo clinic attaches everyone rotating on its unit.';
            } else {
                self::levelHistory($people, $levels, $batch);
            }

            [$period, $generated] = self::period($institutionId, $batch);

            if (! $generated) {
                $skipped[] = 'This department already has periods, so the demo used the existing '
                    .'academic year instead of generating one of its own.';
            }

            if ($period === null) {
                // Only reachable when the department's own periods all sit in the past or the
                // future AND none could be chosen — stated rather than silently producing a
                // department with an empty rota nobody can explain.
                $skipped[] = 'No period could be found to place the demo rota in, so the demo unit '
                    .'has no rota and its clinic resolves to nobody.';
            } else {
                self::rota($people, $period, $unit, $batch);
                self::leave($people, $period, $batch);
            }

            self::clinic($unit, $levels, $institutionId, $batch);
        });

        $rows = count(DemoLedger::rowsFor($batch));

        // AFTER the transaction, one row, ids and counts only. Inside it, this would lock the
        // chain tail for the whole build and unwind its own trail on a rollback.
        AuditLog::record('demo_department_create', "batch={$batch};rows={$rows}", $actorId, $ip);

        return ['batch' => $batch, 'rows' => $rows, 'skipped' => array_values($skipped)];
    }

    /**
     * WHAT WOULD STOP THIS BATCH BEING REMOVED — `(table, count)` pairs, read-only, and safe to run
     * as often as anybody likes. Empty means removal would go through.
     *
     * It walks `DemoReferences::MAP` and counts, per referencing table, the rows that point at a
     * LEDGERED row and are NOT THEMSELVES LEDGERED. That second clause is the whole trick and the
     * easiest thing in this class to get wrong: the demo's own clinic sits on the demo's own unit
     * and its own rota spans name its own people, so a pre-flight that merely counted inbound
     * references would block every removal forever — a department nobody could delete, with every
     * refusal test still passing. `DemoRemoveTest::test_the_demos_own_rows_do_not_block_its_own_
     * removal` is the twin that pins it.
     *
     * DISTINCT ROWS, NOT REFERENCES. One `handover_signoffs` row can name demo people in as many as
     * four of its columns; counting each column separately would report four blockers where an
     * operator has one row to deal with. So the conditions for a referencing table are OR-ed into a
     * single query per table.
     *
     * Soft deletes are deliberately NOT filtered out. A tombstoned handover still holds its foreign
     * key, still occupies its table's unique indexes, and still makes the demo unit undeletable at
     * the database level — the query builder is used precisely so a global scope cannot hide one.
     *
     * @return list<array{table: string, count: int}> ordered by table name
     */
    public static function preflight(string $batch): array
    {
        /** @var array<string, list<int>> $ledgered */
        $ledgered = [];

        foreach (DemoLedger::rowsFor($batch) as $row) {
            $ledgered[$row['table']][] = $row['id'];
        }

        /** @var array<string, list<array{column: string, ids: list<int>}>> $conditions */
        $conditions = [];

        foreach ($ledgered as $table => $ids) {
            foreach (DemoReferences::MAP[$table] ?? [] as $reference) {
                $conditions[$reference['table']][] = ['column' => $reference['column'], 'ids' => $ids];
            }
        }

        $blocked = [];

        foreach ($conditions as $table => $clauses) {
            $query = DB::table($table)->where(static function ($group) use ($clauses): void {
                foreach ($clauses as $clause) {
                    $group->orWhereIn($clause['column'], $clause['ids']);
                }
            });

            // Only applied for a table the demo itself writes. Every such table has an `id`
            // primary key; a foreign table might not, and this way the question is never asked
            // of one.
            if (($ledgered[$table] ?? []) !== []) {
                $query->whereNotIn('id', $ledgered[$table]);
            }

            $count = $query->count();

            if ($count > 0) {
                $blocked[] = ['table' => $table, 'count' => $count];
            }
        }

        usort($blocked, static fn (array $a, array $b): int => $a['table'] <=> $b['table']);

        return $blocked;
    }

    /**
     * Remove one batch — WHOLE, or not at all.
     *
     * The pre-flight runs first and a single blocking row refuses the entire operation. That is the
     * bulk-operation discipline invariant 12 requires and `PeriodController::destroy()` already
     * models: validate the whole set, then mutate, or mutate nothing. A removal that half-applied
     * would tell the operator the demo is still there while half of it was already gone.
     *
     * DELETION IS GENERIC, BY LEDGER, IN REVERSE CREATION ORDER. `DemoLedger::rowsFor()` returns
     * newest first, so a child is deleted before its parent and no restrict-on-delete key fires
     * part-way through. The delete goes through the QUERY BUILDER with the table name taken from
     * the ledger row, which does two things deliberately: it HARD-deletes (`people` soft-deletes,
     * and a tombstoned demo person would still hold its unique email and short name forever), and it
     * enumerates from one place instead of a switch of eight branches that would go stale the moment
     * a ninth table joined. The cost is that no substring guard can see these deletes; the
     * single-writer guards say so in their own docblocks, and `DemoRoundTripTest` is what actually
     * proves the delete reaches every ledgered row and nothing else.
     *
     * The ledger is forgotten LAST, inside the same transaction. An interruption between the two
     * would otherwise leave rows nobody can find again; this way it leaves a ledger naming rows that
     * are already gone, which a re-run treats as already-removed.
     *
     * @return array{batch: string, rows: int}
     *
     * @throws InvalidArgumentException when no such batch exists
     * @throws DemoRemovalBlockedException when a real row references the demo. Nothing is deleted.
     */
    public static function remove(string $batch, ?int $actorId = null, ?string $ip = null): array
    {
        $rows = DemoLedger::rowsFor($batch);

        if ($rows === []) {
            throw new InvalidArgumentException(
                "No demo department was recorded under batch [{$batch}]. Nothing was removed."
            );
        }

        $blocked = self::preflight($batch);

        if ($blocked !== []) {
            AuditLog::record(
                'demo_department_remove_refused',
                "batch={$batch};blocked=".DemoRemovalBlockedException::pairsFor($blocked),
                $actorId,
                $ip,
            );

            throw new DemoRemovalBlockedException($batch, $blocked);
        }

        DB::transaction(function () use ($rows, $batch): void {
            foreach ($rows as $row) {
                // A table that no longer exists took its rows with it, so there is nothing to
                // delete and nothing to report — the ledger entry is forgotten below either way.
                if (! Schema::hasTable($row['table'])) {
                    continue;
                }

                DB::table($row['table'])->where('id', $row['id'])->delete();
            }

            DemoLedger::forgetBatch($batch);
        });

        $count = count($rows);

        AuditLog::record('demo_department_remove', "batch={$batch};rows={$count}", $actorId, $ip);

        return ['batch' => $batch, 'rows' => $count];
    }

    /**
     * The demo unit. Clinic-owning and training-rotation, because a demo that cannot hold a clinic
     * or a rotation demonstrates neither.
     *
     * `display_order` lands after every existing unit so the demo sorts last on every screen it
     * appears on, rather than pushing the department's real units down a row.
     */
    private static function unit(): Unit
    {
        $last = (int) (Unit::query()->max('display_order') ?? 0);

        return Unit::create([
            'code' => self::UNIT_CODE,
            'name' => self::LABEL_PREFIX.'Training Unit',
            'name2' => self::LABEL_PREFIX.'Unit',
            'display_order' => $last + 10,
            // `active` and `clinic_owner` both default to FALSE at the schema level, so a unit
            // created without them is retired on arrival and the clinic writer refuses it.
            'active' => true,
            'training_rotation' => true,
            'clinic_owner' => true,
            'bar_class' => self::UNIT_BAR_CLASS,
        ]);
    }

    /**
     * @return list<Person> in the order {@see PEOPLE} declares them
     */
    private static function people(?int $institutionId, string $batch): array
    {
        $people = [];

        foreach (self::PEOPLE as [$fullName, $shortName, $position]) {
            $person = Person::create([
                'institution_id' => $institutionId,
                'full_name' => $fullName,
                'short_name' => $shortName,
                'position' => $position,
                'email' => self::addressFor($shortName),
                'active' => true,
            ]);

            DemoLedger::record('people', (int) $person->getKey(), $batch);

            $people[] = $person;
        }

        return $people;
    }

    /** `demo-r1@demo.invalid` — lower case, no spaces, and unmistakably not a person's address. */
    private static function addressFor(string $shortName): string
    {
        return strtolower($shortName).'@'.self::EMAIL_DOMAIN;
    }

    /**
     * The department's own ladder, in its own order — never a hardcoded `R1`. LV-01's levels are
     * administrator-editable and a rename survives `db:seed --force`, so reading the codes here
     * would be a second definition of a list the department owns.
     *
     * `EXT` and every other external level is skipped: it sits outside the ladder by design and is
     * never promoted through.
     *
     * @return list<Level>
     */
    private static function ladder(): array
    {
        return Level::query()
            ->active()
            ->where('external', false)
            ->orderBy('display_order')
            ->get()
            ->all();
    }

    /**
     * A level span per resident, opened well before any period the demo could place them in, so
     * `Person::levelAt()` resolves on the first day of the rota rather than one day after it.
     *
     * The consultant gets none: a training level is orthogonal to a job role (design §5.1) and a
     * consultant holding an `R1` badge is exactly the wrong thing for a training fixture to teach.
     *
     * @param  list<Person>  $people
     * @param  list<Level>  $levels
     */
    private static function levelHistory(array $people, array $levels, string $batch): void
    {
        $from = Calendar::addDays(Calendar::todayYmd(), -365)->format(Calendar::YMD);
        $rung = 0;

        foreach ($people as $person) {
            if ((int) $person->position === 3) {
                continue;
            }

            $level = $levels[$rung % count($levels)];
            $rung++;

            if (LevelAssignment::assign($person, $level, $from) !== LevelAssignment::ASSIGNED) {
                continue;
            }

            // The span the writer just opened. A brand-new person holds exactly one, so the
            // newest row for them IS it — no date comparison, and no second definition of the
            // bound the writer chose.
            $id = PersonLevel::query()
                ->where('person_id', $person->getKey())
                ->orderByDesc('id')
                ->value('id');

            if ($id !== null) {
                DemoLedger::record('person_levels', (int) $id, $batch);
            }
        }
    }

    /**
     * The period the demo rota lives in, and whether the demo generated it.
     *
     * NEVER A SECOND ACADEMIC YEAR ALONGSIDE A REAL ONE (Decision F). If the department has any
     * periods at all, the demo uses the one covering today — or, failing that, the next one to
     * end — and generates nothing. A demo year sitting beside a real one would also block the
     * Periods screen from generating that real year, since generating twice for one label is
     * refused outright.
     *
     * When it DOES generate, it generates under its own label rather than a derived
     * `2026-2027`-shaped one, for the same reason every other value here carries the prefix: the
     * year picker on the rota screen is a place an administrator has to be able to tell the demo
     * apart at a glance.
     *
     * @return array{0: Period|null, 1: bool}
     */
    private static function period(?int $institutionId, string $batch): array
    {
        $today = Calendar::todayYmd();

        if (Period::query()->exists()) {
            $chosen = Calendar::periodFor($today)
                ?? Period::query()->whereDate('ends_on', '>=', $today)->orderBy('ends_on')->first()
                ?? Period::query()->orderByDesc('ends_on')->first();

            return [$chosen, false];
        }

        // Two blocks, the first containing today so the clinic resolves the moment the screen is
        // opened, the second so the rota has a boundary to demonstrate. Every bound goes through
        // the one date module (AR-08) — nothing here constructs a date of its own.
        $blocks = [
            [1, Calendar::addDays($today, -13), Calendar::addDays($today, 14)],
            [2, Calendar::addDays($today, 15), Calendar::addDays($today, 42)],
        ];

        $first = null;

        foreach ($blocks as [$position, $startsOn, $endsOn]) {
            $period = Period::create([
                'institution_id' => $institutionId,
                'academic_year' => self::ACADEMIC_YEAR,
                'kind' => Period::WEEK_BLOCK,
                'position' => $position,
                'label' => self::LABEL_PREFIX.'Block '.$position,
                'starts_on' => $startsOn->format(Calendar::YMD),
                'ends_on' => $endsOn->format(Calendar::YMD),
            ]);

            DemoLedger::record('periods', (int) $period->getKey(), $batch);

            $first ??= $period;
        }

        return [$first, true];
    }

    /**
     * Everybody on the demo unit for the chosen period, and ONE resident on a shortened span so
     * the department's coverage summary has a real gap in it.
     *
     * MR-02 accepts gaps and refuses overlaps (owner decision 3): a half-planned block is a state
     * the grid renders rather than refuses, and a demo whose every cell is full teaches an
     * administrator that the uncovered-days count is always zero. The shortened span ends three
     * days before the period does, and is only used when the period is long enough for that to
     * still leave today covered — the clinic must resolve on the day the demo is created.
     *
     * @param  list<Person>  $people
     */
    private static function rota(array $people, Period $period, Unit $unit, string $batch): void
    {
        $periodStart = $period->starts_on->format(Calendar::YMD);
        $periodEnd = $period->ends_on->format(Calendar::YMD);
        $shortEnd = Calendar::addDays($periodEnd, -3)->format(Calendar::YMD);
        $splittable = $shortEnd > $periodStart && $shortEnd >= Calendar::todayYmd();

        $split = $splittable ? end($people) : null;

        foreach ($people as $person) {
            if ($split !== null && $person->is($split)) {
                RotaAssignment::split($person, $period, [[
                    'unit_id' => (int) $unit->getKey(),
                    'starts_on' => $periodStart,
                    'ends_on' => $shortEnd,
                ]]);
            } else {
                RotaAssignment::set($person, $period, $unit);
            }

            foreach (self::spanIdsFor($person, $period) as $id) {
                DemoLedger::record('master_rota_assignments', $id, $batch);
            }
        }
    }

    /**
     * @return list<int> every span this person holds in this period, which the writer has just
     *                   REPLACED wholesale — so reading them back is reading what it wrote, not
     *                   guessing at it.
     */
    private static function spanIdsFor(Person $person, Period $period): array
    {
        return MasterRotaAssignment::query()
            ->where('person_id', $person->getKey())
            ->where('period_id', $period->getKey())
            ->orderBy('id')
            ->pluck('id')
            ->map(static fn ($id): int => (int) $id)
            ->all();
    }

    /**
     * One week of leave for one resident, so MR-07's per-week vacation strip is not a row of
     * zeroes and the availability summary has something to subtract.
     *
     * `vacations` carries no period (CLAUDE.md — a vacation crosses period boundaries and is not a
     * rotation), so the bound below is a date inside the chosen period and the writer snaps it to
     * the DEPARTMENT's own week, derived from its configured weekend.
     *
     * @param  list<Person>  $people
     */
    private static function leave(array $people, Period $period, string $batch): void
    {
        $resident = null;

        foreach ($people as $person) {
            if ((int) $person->position === 4) {
                $resident = $person;
                break;
            }
        }

        if ($resident === null) {
            return;
        }

        $on = Calendar::addDays($period->starts_on->format(Calendar::YMD), 7)->format(Calendar::YMD);

        $booked = VacationBooking::book($resident, $on, $on, \App\Models\Vacation::GRANULARITY_WEEK);

        DemoLedger::record('vacations', (int) $booked->getKey(), $batch);
    }

    /**
     * CL-01 and CL-02 in one row: a weekly clinic on the demo unit, refined to the training levels
     * the demo residents hold, so `ClinicRoster` has all three of its inputs (the rule, the master
     * rota and a date) and resolves to real people on the day the department is created.
     *
     * The weekday is the FIRST working day of the department's own week — derived, never a
     * hardcoded Monday, so a department that moves its weekend moves this clinic with it and no
     * stored value changes.
     *
     * With an empty ladder the clinic stays on CL-02's default (everyone the rota has on the unit),
     * which needs no rule rows at all. That is a real state on a fresh instance, not a fallback.
     *
     * @param  list<Level>  $levels
     */
    private static function clinic(Unit $unit, array $levels, ?int $institutionId, string $batch): void
    {
        $clinic = ClinicWriter::create($unit, [
            'name' => self::LABEL_PREFIX.'Follow-up Clinic',
            'weekday' => self::firstWorkingIsoDay(),
            'session' => array_key_first(Clinic::SESSIONS),
            'location' => self::LABEL_PREFIX.'Clinic Room',
            'note' => 'Part of the demo department. Removing the demo removes this clinic with it.',
        ], $institutionId);

        DemoLedger::record('clinics', (int) $clinic->getKey(), $batch);

        if ($levels === []) {
            return;
        }

        ClinicWriter::setAttendees($clinic, Clinic::MODE_LEVELS, array_map(
            static fn (Level $level): array => ['level_id' => (int) $level->getKey()],
            array_slice($levels, 0, 2),
        ));

        foreach ($clinic->attendees()->orderBy('id')->pluck('id') as $id) {
            DemoLedger::record('clinic_attendees', (int) $id, $batch);
        }
    }

    /**
     * The first non-weekend column of the department's week, ISO-8601 (Monday = 1 … Sunday = 7).
     * `Calendar::weekdayColumns()` already orders the week from its own configured start and flags
     * each column, so this reads an answer rather than computing one.
     */
    private static function firstWorkingIsoDay(): int
    {
        foreach (Calendar::weekdayColumns() as $column) {
            if (! $column['weekend']) {
                return (int) $column['iso'];
            }
        }

        // Every day configured as a weekend is not a state the settings screen can produce, but a
        // clinic still needs a day. The week's own first column is the least surprising one.
        return Calendar::weekStartIsoDay();
    }
}
