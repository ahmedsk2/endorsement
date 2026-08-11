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
use App\Support\Rota\StatePin;
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
     * The two things a demo department does DIFFERENTLY depending on the department it lands in,
     * written once and read by both `plan()` (which predicts them) and `create()` (which reports
     * them afterwards). Present tense in both directions deliberately: a preview and a receipt of
     * the same fact should not be two strings that can drift apart in meaning.
     */
    private const SKIP_NO_LADDER = 'No active training level exists yet, so the demo people carry no '
        .'level history and the demo clinic attaches everyone rotating on its unit.';

    private const SKIP_EXISTING_PERIODS = 'This department already has periods, so the demo uses the '
        .'existing academic year instead of generating one of its own.';

    private const SKIP_NO_PERIOD = 'No period could be found to place the demo rota in, so the demo '
        .'unit has no rota and its clinic resolves to nobody.';

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
        // never a half-built department. `refusals()` is read-only, names the remedy, and is the
        // SAME list the screen renders before offering the button, so the two cannot disagree.
        $refusals = self::refusals();

        if ($refusals !== []) {
            throw new InvalidArgumentException($refusals[0]);
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
                $skipped[] = self::SKIP_NO_LADDER;
            } else {
                self::levelHistory($people, $levels, $batch);
            }

            [$period, $generated] = self::period($institutionId, $batch);

            if (! $generated) {
                $skipped[] = self::SKIP_EXISTING_PERIODS;
            }

            if ($period === null) {
                // Only reachable when the department's own periods all sit in the past or the
                // future AND none could be chosen — stated rather than silently producing a
                // department with an empty rota nobody can explain.
                $skipped[] = self::SKIP_NO_PERIOD;
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
     * WHY A DEMO DEPARTMENT COULD NOT BE CREATED RIGHT NOW — a list of sentences, each naming its
     * own remedy. Empty means the button may be offered.
     *
     * ONE DEFINITION, TWO CONSUMERS, the `PeriodGenerator::assertMonthAligned()` shape: `create()`
     * throws the first of these and the screen renders all of them BEFORE offering the control. A
     * screen that decided for itself when to grey a button out would be a second copy of this rule,
     * and the two would disagree the first time either changed.
     *
     * @return list<string>
     */
    private static function refusals(): array
    {
        $refusals = [];

        if (DemoLedger::has()) {
            $refusals[] = 'A demo department already exists. Remove it first, then create a new one — two of '
                .'them cannot be told apart on screen, and only one can hold the demo unit code.';
        }

        if (Unit::findByCode(self::UNIT_CODE) !== null) {
            $refusals[] = 'A unit with the code ['.self::UNIT_CODE.'] already exists and is not part of any '
                .'demo department. Rename or retire it before creating the demo.';
        }

        return $refusals;
    }

    /**
     * WHAT PRESSING CREATE WOULD DO, read-only — the preview half of ST-05's one click.
     *
     * `refusals` is why it cannot be pressed; `skipped` is what a demo department will NOT do in
     * THIS department, in the same words `create()` reports afterwards. `facts` is what those two
     * are derived from, and it exists so the pin below can be taken over the inputs rather than
     * over their rendering: two different worlds can produce the same sentence, and the sentence is
     * not what decides the outcome.
     *
     * @return array{refusals: list<string>, skipped: list<string>, facts: array<string, mixed>}
     */
    public static function plan(): array
    {
        $ladder = array_map(static fn (Level $level): int => (int) $level->getKey(), self::ladder());
        $hasPeriods = Period::query()->exists();

        $skipped = [];

        if ($ladder === []) {
            $skipped[] = self::SKIP_NO_LADDER;
        }

        if ($hasPeriods) {
            $skipped[] = self::SKIP_EXISTING_PERIODS;
        }

        return [
            'refusals' => self::refusals(),
            'skipped' => $skipped,
            'facts' => ['ladder' => $ladder, 'has_periods' => $hasPeriods],
        ];
    }

    /**
     * WHAT A CREATION IS PINNED TO.
     *
     * {@see StatePin} is the one definition of a preview-then-confirm pin, and this operation has
     * its shape with one slot legitimately empty. `identity` carries the operation's own inputs —
     * and a creation's inputs are ENTIRELY the state of the department it lands in, because the
     * operator supplies nothing at all. `errors` carries the whole-operation refusals, which are
     * part of what the operator was shown. `cells` is empty BY CONSTRUCTION rather than by
     * omission: there is no existing row this writes over, so there is no current-versus-proposed
     * pair to project.
     *
     * WHAT IT CATCHES THAT `create()` CANNOT. `create()` re-derives everything inside its own
     * transaction and is right to — but re-deriving means it computes a FRESH answer, so a
     * department that generated its academic year between the preview and the confirm gets a demo
     * placed in the REAL year, silently, when the operator was told a demo year would be generated.
     * Same for a training ladder appearing: the demo's clinic goes from "everyone on the unit" to a
     * level-refined rule. Neither is an error and neither can be noticed after the fact.
     *
     * @param  array{refusals: list<string>, skipped: list<string>, facts: array<string, mixed>}  $plan
     */
    public static function planDigest(array $plan): string
    {
        return StatePin::of('demo_department_create', $plan['facts'], $plan['refusals'], []);
    }

    /**
     * WHAT A REMOVAL WOULD DELETE AND WHAT WOULD STOP IT — read-only, and the whole payload the
     * screen renders before it asks for the word to be typed.
     *
     * `rows` is the ledger's own list, which is what the delete walks; `counts` is that list per
     * table, which is what a human can actually check ("one unit, five people, one clinic") where a
     * bare total is a number nobody can verify.
     *
     * @return array{batch: string, rows: list<array{table: string, id: int}>, counts: list<array{table: string, count: int}>, blocked: list<array{table: string, count: int}>}
     */
    public static function removalState(string $batch): array
    {
        $rows = DemoLedger::rowsFor($batch);

        $counts = [];

        foreach ($rows as $row) {
            $counts[$row['table']] = ($counts[$row['table']] ?? 0) + 1;
        }

        ksort($counts);

        return [
            'batch' => $batch,
            'rows' => $rows,
            'counts' => array_values(array_map(
                static fn (string $table, int $count): array => ['table' => $table, 'count' => $count],
                array_keys($counts),
                $counts,
            )),
            'blocked' => $rows === [] ? [] : self::preflight($batch),
        ];
    }

    /**
     * WHAT A REMOVAL IS PINNED TO.
     *
     * Same one definition ({@see StatePin}), and here the cell shape is the strained one, stated
     * rather than papered over: a ledger row's identity is `(table, id)`, and NEITHER of StatePin's
     * two identity slots — a person and a period — applies to it. Both are therefore `null`, which
     * is exactly what that parameter documents for a concept that does not apply, and the identity
     * lives in the `current` map beside what the row is. `proposed` is empty because this operation
     * writes nothing anywhere: after it, the row is not there.
     *
     * WHAT IT CATCHES THAT `remove()` CANNOT. Two administrators: the first opens the screen, the
     * second removes the demo and creates a fresh one, the first presses Remove. `remove()` takes
     * the batch it is given, re-runs its pre-flight, finds nothing in the way and removes a
     * department this operator never previewed — and every table returns to its pre-seed count, so
     * not the round trip, not the pre-flight and not the audit trail shows anything amiss. The
     * batch in `identity` is what refuses it. The blockers ride in `errors` for the ordinary case:
     * a removal previewed as clear, confirmed after real work landed on it.
     *
     * @param  array{batch: string, rows: list<array{table: string, id: int}>, counts: list<array{table: string, count: int}>, blocked: list<array{table: string, count: int}>}  $state
     */
    public static function removalDigest(array $state): string
    {
        return StatePin::of(
            'demo_department_remove',
            ['batch' => $state['batch']],
            array_map(
                static fn (array $row): string => $row['table'].':'.$row['count'],
                $state['blocked'],
            ),
            array_map(
                static fn (array $row): array => [null, null, [['table' => $row['table'], 'id' => $row['id']]], []],
                $state['rows'],
            ),
        );
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
     * IT RUNS TWICE, AND THE SECOND ONE IS THE LOAD-BEARING ONE (P1e-2 review finding 1). The outer
     * call is the cheap early refusal: it costs one query per referencing table, needs no
     * transaction, and is what writes `demo_department_remove_refused` for the ordinary case. The
     * INNER one — first statement inside the transaction, after the parent rows are locked — is what
     * makes the refusal true. A check made outside the transaction it guards decides against a world
     * that no longer exists by the time the first DELETE runs, and THE DATABASE IS NOT A BACKSTOP
     * HERE the way it is for `PeriodController::destroy()`: of the nineteen inbound keys in
     * `DemoReferences::MAP` only three are RESTRICT. Nine are `ON DELETE SET NULL`, including all
     * four of `handover_signoffs`' `*_person_id` columns — so the unguarded shape does not raise, it
     * SUCCEEDS, writes a clean audit row, and leaves a medico-legal attestation naming nobody.
     * Measured on this tree before the fix: `rows=21`, no refusal, `endorsed_by_person_id` NULLed.
     *
     * THE LOCK IS NOT DECORATION, AND SQLITE CANNOT SHOW WHY. A plain re-read is sufficient on
     * SQLite, which serialises writers and compiles `lockForUpdate()` to the empty string. It is NOT
     * sufficient on MySQL 8.4: under REPEATABLE READ a DELETE is a *current* read and sees rows a
     * concurrent session committed after this transaction's snapshot, while a non-locking SELECT
     * beside it still reads the snapshot — so the re-check would come back clean against a row the
     * delete is about to orphan. InnoDB's shared lock on the parent row is what blocks a concurrent
     * FK-child insert; the idiom is `AuditLog::record()`'s and `InvitationAcceptController`'s.
     *
     * `Schema::hasTable()` IS ASKED BEFORE THE TRANSACTION OPENS. It compiles to an uncached
     * `information_schema` query on MySQL (`MySqlGrammar::compileTableExists()`), so asking it once
     * per ledgered row from inside the transaction body is ~21 catalogue round trips spent holding
     * locks open for nothing. A table cannot appear or vanish underneath a removal.
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
            self::auditRefusal($batch, $blocked, $actorId, $ip);

            throw new DemoRemovalBlockedException($batch, $blocked);
        }

        // A table that no longer exists took its rows with it, so there is nothing to delete and
        // nothing to report — the ledger entry is forgotten below either way. Asked HERE and not
        // in the closure: see the docblock.
        $deletable = array_values(array_filter(
            $rows,
            static fn (array $row): bool => Schema::hasTable($row['table']),
        ));

        try {
            DB::transaction(function () use ($rows, $deletable, $batch): void {
                self::lockLedgeredRows($rows);

                // THE LOAD-BEARING CHECK. Same definition as the one above; a second predicate here
                // would be two answers to one question, which is the drift this codebase keeps
                // paying for. It throws from inside so a blocker found after the outer check takes
                // the whole delete down with it.
                $blocked = self::preflight($batch);

                if ($blocked !== []) {
                    throw new DemoRemovalBlockedException($batch, $blocked);
                }

                foreach ($deletable as $row) {
                    DB::table($row['table'])->where('id', $row['id'])->delete();
                }

                DemoLedger::forgetBatch($batch);
            });
        } catch (DemoRemovalBlockedException $e) {
            // Audited from OUT HERE, after the unwind. A row written from inside would roll back
            // with the transaction that raised it and the refusal would leave no trace at all —
            // `RosterImport`'s finding 6, arrived at through a different door.
            self::auditRefusal($batch, $e->blocked(), $actorId, $ip);

            throw $e;
        }

        $count = count($rows);

        AuditLog::record('demo_department_remove', "batch={$batch};rows={$count}", $actorId, $ip);

        return ['batch' => $batch, 'rows' => $count];
    }

    /**
     * Take a shared lock on every ledgered row before the in-transaction re-check.
     *
     * A NO-OP ON SQLITE, ON PURPOSE AND UNAVOIDABLY: `SQLiteGrammar::compileLock()` returns the
     * empty string, and SQLite serialises writers anyway, so this suite cannot observe it. It is the
     * whole guard on InnoDB, where a non-locking SELECT reads the transaction's snapshot while the
     * DELETE beside it reads current rows. Holding the parent row is what blocks a concurrent
     * session inserting a child that points at it.
     *
     * EVERY ledgered row, not only the ones `DemoReferences::MAP` currently shows a child for: the
     * lock set then follows the map rather than being a second, hand-maintained list of which
     * tables have inbound keys today. It is tens of rows.
     *
     * @param  list<array{table: string, id: int}>  $rows
     */
    private static function lockLedgeredRows(array $rows): void
    {
        /** @var array<string, list<int>> $byTable */
        $byTable = [];

        foreach ($rows as $row) {
            $byTable[$row['table']][] = $row['id'];
        }

        foreach ($byTable as $table => $ids) {
            DB::table($table)->whereIn('id', $ids)->lockForUpdate()->get();
        }
    }

    /**
     * ONE definition of the refused-removal audit row, called from both refusal paths — the cheap
     * early one and the one the transaction raised. Two spellings of this string would be two
     * `blocked=` formats in one trail.
     *
     * @param  list<array{table: string, count: int}>  $blocked
     */
    private static function auditRefusal(string $batch, array $blocked, ?int $actorId, ?string $ip): void
    {
        AuditLog::record(
            'demo_department_remove_refused',
            "batch={$batch};blocked=".DemoRemovalBlockedException::pairsFor($blocked),
            $actorId,
            $ip,
        );
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
