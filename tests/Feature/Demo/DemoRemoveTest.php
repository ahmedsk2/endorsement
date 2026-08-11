<?php

namespace Tests\Feature\Demo;

use App\Models\AuditLog;
use App\Models\Clinic;
use App\Models\Handover;
use App\Models\HandoverSignoff;
use App\Models\Institution;
use App\Models\Invitation;
use App\Models\Period;
use App\Models\Person;
use App\Models\Unit;
use App\Models\User;
use App\Models\Vacation;
use App\Support\Calendar;
use App\Support\Clinics\ClinicWriter;
use App\Support\Demo\DemoDepartment;
use App\Support\Demo\DemoLedger;
use App\Support\Demo\DemoReferences;
use App\Support\Demo\DemoRemovalBlockedException;
use App\Support\Rota\RotaAssignment;
use App\Support\Rota\VacationBooking;
use Database\Seeders\AccessControlSeeder;
use Database\Seeders\ReferenceSeeder;
use Illuminate\Database\Events\TransactionBeginning;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use InvalidArgumentException;
use ReflectionMethod;
use Tests\Support\TableCounts;
use Tests\TestCase;

/**
 * Munawib ST-05's hardest word (P1e Decision F, Task 13): REMOVABLE — and removable only when
 * nothing real has leaned on the demo since it was created.
 *
 * THE REFUSAL IS WHOLE, NEVER PARTIAL, and every case below asserts that twice: that it refused,
 * AND that nothing was deleted. A refusal that half-applied is worse than no refusal at all,
 * because the operator is told the department is still there while half of it is gone. This is
 * `PeriodController::destroy()`'s shape ("this academic year has N master rota assignments against
 * it … clear the rota first") applied to a set of tables instead of one.
 *
 * THE CASES ARE REAL, NOT HYPOTHETICAL. A training session runs on the demo unit and somebody
 * writes a genuine handover on it. An invitation reaches a demo person and an account is claimed
 * against them. A real clinic names a demo resident while the demo is still up. And — the one that
 * must never be reachable from a cleanup button — a sign-off names a demo person in one of its four
 * `*_person_id` columns, which is medico-legal evidence.
 *
 * THE REFERENCE MAP IS ASSERTED AGAINST THE LIVE SCHEMA, NEVER TRUSTED. `DemoReferences::MAP` is
 * hand-written, and a hand-written list of foreign keys is a list that goes stale the next time
 * somebody adds one. Both directions are pinned by introspection below — the precedent is
 * `ReservedUnitCodesTest::test_the_reserved_list_covers_every_literal_route_segment`, which derives
 * its expectation from the registered routes rather than from the constant it guards.
 */
class DemoRemoveTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(ReferenceSeeder::class);
        $this->seed(AccessControlSeeder::class);
    }

    /** @return list<Person> the demo's own people, in creation order. */
    private function demoPeople(string $batch): array
    {
        $ids = array_values(array_map(
            static fn (array $row): int => $row['id'],
            array_filter(DemoLedger::rowsFor($batch), static fn (array $row): bool => $row['table'] === 'people'),
        ));

        // `rowsFor()` is newest-first; sorting by key restores creation order, which is the order
        // `DemoDepartment::PEOPLE` declares (consultant, chief, three residents).
        sort($ids);

        return Person::query()->whereKey($ids)->orderBy('id')->get()->all();
    }

    private function demoUnit(): Unit
    {
        $unit = Unit::findByCode(DemoDepartment::UNIT_CODE);

        $this->assertNotNull($unit);

        return $unit;
    }

    /**
     * The whole-schema snapshot MINUS `audit_log`, which every refusal below deliberately grows by
     * exactly one row. "Nothing was deleted" is the claim; "nothing was written" is not, because a
     * refused removal is an operator action the hash-chained trail is supposed to record.
     *
     * @return array<string, int>
     */
    private function countsWithoutAudit(): array
    {
        $counts = TableCounts::snapshot();

        unset($counts[TableCounts::qualify('audit_log')]);

        return $counts;
    }

    /** A person who is NOT part of the demo — the "real work" half of every refusal below. */
    private function realPerson(string $email = 'real@example.org'): Person
    {
        return Person::create([
            'institution_id' => Institution::query()->value('id'),
            'full_name' => 'Real Person', 'short_name' => 'realp', 'position' => 4,
            'email' => $email, 'active' => true,
        ]);
    }

    /**
     * @param  list<string>  $tables
     * @return array<string, int>
     */
    private function blockedByTable(DemoRemovalBlockedException $e): array
    {
        $map = [];

        foreach ($e->blocked() as $row) {
            $map[$row['table']] = $row['count'];
        }

        return $map;
    }

    // --- The happy path --------------------------------------------------------------------------

    public function test_removal_deletes_every_ledgered_row_and_the_ledger_entries_with_them(): void
    {
        $created = DemoDepartment::create();
        $rows = DemoLedger::rowsFor($created['batch']);

        $this->assertNotSame([], $rows);

        $result = DemoDepartment::remove($created['batch']);

        $this->assertSame($created['batch'], $result['batch']);
        $this->assertSame(count($rows), $result['rows']);

        $survivors = [];

        foreach ($rows as $row) {
            if (Schema::hasTable($row['table'])
                && \Illuminate\Support\Facades\DB::table($row['table'])->where('id', $row['id'])->exists()) {
                $survivors[] = $row['table'].' '.$row['id'];
            }
        }

        $this->assertSame([], $survivors, 'Every ledgered row is gone.');
        $this->assertFalse(DemoLedger::has(), 'And the ledger forgot the batch with them.');
    }

    /**
     * THE VACUITY TWIN FOR THE PRE-FLIGHT, and the one that would have shipped a department nobody
     * could ever remove. The demo's own clinic sits on the demo unit; its own rota spans name its
     * own people and its own periods. A pre-flight that counted inbound references WITHOUT
     * excluding the ledgered ones would block every removal forever, and every refusal test below
     * would still be green.
     */
    public function test_the_demos_own_rows_do_not_block_its_own_removal(): void
    {
        $created = DemoDepartment::create();

        $this->assertSame([], DemoDepartment::preflight($created['batch']));
    }

    /** The lifecycle closes: create, remove, create again. */
    public function test_a_second_demo_department_may_be_created_after_a_removal(): void
    {
        $first = DemoDepartment::create();
        DemoDepartment::remove($first['batch']);

        $second = DemoDepartment::create();

        $this->assertNotSame($first['batch'], $second['batch']);
        $this->assertSame([$second['batch']], DemoLedger::batches());
        $this->assertNotNull(Unit::findByCode(DemoDepartment::UNIT_CODE));
    }

    /** Asking costs nothing and changes nothing — a preview an operator can run before deciding. */
    public function test_the_preflight_writes_nothing(): void
    {
        $created = DemoDepartment::create();

        $this->realPerson();
        $before = TableCounts::snapshot();

        DemoDepartment::preflight($created['batch']);

        // The FULL snapshot here, `audit_log` included: asking is a query, not an operation, and it
        // writes no trail row of its own. Only `remove()` audits — in either direction.
        $this->assertSame($before, TableCounts::snapshot());
    }

    /** A batch nobody ever created is a refusal, not a silent success that reports "removed 0". */
    public function test_removing_an_unknown_batch_is_refused(): void
    {
        DemoDepartment::create();

        $this->expectException(InvalidArgumentException::class);

        DemoDepartment::remove('11111111-2222-3333-4444-555555555555');
    }

    // --- Refusals --------------------------------------------------------------------------------

    public function test_removal_is_refused_whole_when_a_real_handover_sits_on_a_demo_unit(): void
    {
        $created = DemoDepartment::create();

        Handover::create([
            'unit_id' => $this->demoUnit()->getKey(),
            'handover_date' => Calendar::todayYmd(),
            'bed' => '1', 'mrn' => 'M-1', 'patient_name' => 'A Child',
        ]);

        $before = $this->countsWithoutAudit();

        try {
            DemoDepartment::remove($created['batch']);
            $this->fail('Removal must be refused while a real handover sits on the demo unit.');
        } catch (DemoRemovalBlockedException $e) {
            $this->assertSame(['handovers' => 1], $this->blockedByTable($e));
        }

        $this->assertSame($before, $this->countsWithoutAudit(), 'NOTHING was deleted — the refusal is whole.');
        $this->assertTrue(DemoLedger::has());
    }

    public function test_removal_is_refused_when_a_real_account_is_bound_to_a_demo_person(): void
    {
        $created = DemoDepartment::create();
        $person = $this->demoPeople($created['batch'])[0];

        User::create([
            'member_name' => 'claimed', 'password' => 'Str0ng-pass!x', 'active' => true,
            'person_id' => $person->getKey(),
        ]);

        $before = $this->countsWithoutAudit();

        try {
            DemoDepartment::remove($created['batch']);
            $this->fail('Removal must be refused while a real account is bound to a demo person.');
        } catch (DemoRemovalBlockedException $e) {
            $this->assertSame(['users' => 1], $this->blockedByTable($e));
        }

        $this->assertSame($before, $this->countsWithoutAudit());
    }

    public function test_removal_is_refused_when_a_real_rota_span_or_vacation_touches_a_demo_row(): void
    {
        $created = DemoDepartment::create();
        $period = Period::query()->orderBy('starts_on')->firstOrFail();

        // A real colleague put on the DEMO unit during training — a real row referencing a demo one.
        RotaAssignment::set($this->realPerson(), $period, $this->demoUnit());

        // And a real leave row against a DEMO person, booked outside the demo's own build.
        VacationBooking::book(
            $this->demoPeople($created['batch'])[0],
            Calendar::addDays(Calendar::todayYmd(), 60)->format(Calendar::YMD),
            Calendar::addDays(Calendar::todayYmd(), 60)->format(Calendar::YMD),
            Vacation::GRANULARITY_DATE,
        );

        $before = $this->countsWithoutAudit();

        try {
            DemoDepartment::remove($created['batch']);
            $this->fail('Removal must be refused while real rota or leave rows touch the demo.');
        } catch (DemoRemovalBlockedException $e) {
            $this->assertSame(
                ['master_rota_assignments' => 1, 'vacations' => 1],
                $this->blockedByTable($e),
            );
        }

        $this->assertSame($before, $this->countsWithoutAudit());

        // TWO blocking tables in one detail string, COMMA-separated inside a single `blocked=`
        // value. The plan sketched `blocked=<table>:<count>;…`, which breaks the house
        // semicolon-delimited key=value convention the moment there is more than one pair — the
        // second pair would read as a key of its own. One key, one value, still ids and counts only.
        $refused = AuditLog::query()->where('action', 'demo_department_remove_refused')->firstOrFail();

        $this->assertSame(
            "batch={$created['batch']};blocked=master_rota_assignments:1,vacations:1",
            (string) $refused->detail,
        );
    }

    public function test_removal_is_refused_when_a_real_clinic_names_a_demo_person_as_an_attendee(): void
    {
        $created = DemoDepartment::create();

        $ward = Unit::findByCode('WARD');
        $this->assertNotNull($ward);

        $clinic = ClinicWriter::create($ward, ['name' => 'A real clinic', 'weekday' => 2, 'session' => 'AM']);
        ClinicWriter::setAttendees($clinic, Clinic::MODE_NAMED, [
            ['person_id' => $this->demoPeople($created['batch'])[2]->getKey()],
        ]);

        $before = $this->countsWithoutAudit();

        try {
            DemoDepartment::remove($created['batch']);
            $this->fail('Removal must be refused while a real clinic names a demo person.');
        } catch (DemoRemovalBlockedException $e) {
            $this->assertSame(['clinic_attendees' => 1], $this->blockedByTable($e));
        }

        $this->assertSame($before, $this->countsWithoutAudit());
    }

    /**
     * MEDICO-LEGAL EVIDENCE, and the case that decides the whole design. A sign-off freezes a named
     * person onto a day's attestation; a "clean up the demo" button that could reach it would
     * delete the row a signature points at.
     */
    public function test_removal_is_refused_when_a_signoff_names_a_demo_person(): void
    {
        $created = DemoDepartment::create();

        HandoverSignoff::create([
            'unit_id' => Unit::findByCode('WARD')?->getKey(),
            'handover_date' => Calendar::todayYmd(),
            'endorsed_by_person_id' => $this->demoPeople($created['batch'])[1]->getKey(),
            'endorsed_by_name' => 'Demo Chief Resident One',
        ]);

        $before = $this->countsWithoutAudit();

        try {
            DemoDepartment::remove($created['batch']);
            $this->fail('Removal must be refused while a sign-off names a demo person.');
        } catch (DemoRemovalBlockedException $e) {
            $this->assertSame(['handover_signoffs' => 1], $this->blockedByTable($e));
        }

        $this->assertSame($before, $this->countsWithoutAudit());
    }

    /**
     * INVARIANT 9, on the refusal itself: tables and counts, never a name. The message is read by a
     * human on screen, and it is also what the audit row is built from — so a person's name leaking
     * in here leaks into the trail as well.
     */
    public function test_the_refusal_names_tables_and_counts_and_never_a_name(): void
    {
        $created = DemoDepartment::create();

        Handover::create([
            'unit_id' => $this->demoUnit()->getKey(),
            'handover_date' => Calendar::todayYmd(),
            'bed' => '1', 'mrn' => 'M-1', 'patient_name' => 'A Child',
        ]);

        try {
            DemoDepartment::remove($created['batch']);
            $this->fail('Removal must be refused.');
        } catch (DemoRemovalBlockedException $e) {
            $message = $e->getMessage();

            $this->assertStringContainsString('handovers', $message);
            $this->assertStringContainsString('1', $message);

            // The remedy, named — "refused" with no next step sends people to a database console.
            $this->assertStringContainsString('first', strtolower($message));

            foreach (['A Child', 'M-1', 'Demo Consultant One', 'Demo Training Unit', 'demo.invalid'] as $name) {
                $this->assertStringNotContainsString($name, $message);
            }
        }
    }

    // --- A blocker whose remedy the product does not offer -----------------------------------------

    /**
     * AN INVITATION TO A DEMO PERSON MADE THE DEMO PERMANENTLY UNREMOVABLE (P1e-2 review, finding 2).
     *
     * `invitations` is never deleted anywhere in this product: `InvitationController::revoke()`
     * stamps `revoked_at`, resend supersedes and KEEPS the old row, and design §14 item 7 records
     * that invitations have no retention rule at all. So practising the invitation flow on a demo
     * person — which `/admin/setup`'s own invitations step pushes an administrator towards — pinned
     * the demo in place for good, behind a refusal naming a remedy nobody could perform.
     *
     * SO REMOVAL SWEEPS IT, AND MERELY UN-BLOCKING IT WOULD HAVE BEEN WORSE THAN THE BUG.
     * `invitations.person_id` is `nullOnDelete`, and `InvitationAcceptController` treats a null
     * `person_id` as "create the person at redemption time" — so a link left behind by a removal
     * would not merely dangle, it would mint a brand-new person AND account for whoever still holds
     * it. The row has to go, not just stop counting.
     */
    public function test_an_invitation_to_a_demo_person_does_not_block_removal_and_goes_with_it(): void
    {
        $created = DemoDepartment::create();
        $person = $this->demoPeople($created['batch'])[2];

        Invitation::query()->create([
            'person_id' => $person->getKey(),
            'member_email' => (string) $person->email,
            'position' => 4,
            'token_hash' => hash('sha256', Str::random(40)),
            'expires_at' => Calendar::now()->addDays(Invitation::LIFETIME_DAYS),
        ]);

        // A real invitation to a real person, which must be untouched: the sweep is scoped to the
        // ledger, exactly as the delete is.
        $realInvitation = Invitation::query()->create([
            'person_id' => $this->realPerson()->getKey(),
            'member_email' => 'real@example.org',
            'position' => 4,
            'token_hash' => hash('sha256', Str::random(40)),
            'expires_at' => Calendar::now()->addDays(Invitation::LIFETIME_DAYS),
        ]);

        $this->assertSame([], DemoDepartment::preflight($created['batch']),
            'an invitation addressed to a demo person is a demo artefact, not a blocker');

        $result = DemoDepartment::remove($created['batch']);

        $this->assertSame(1, $result['swept'], 'the invitation went with the department it named');
        $this->assertFalse(DemoLedger::has());

        $survivors = Invitation::query()->pluck('id')->all();

        $this->assertSame([$realInvitation->getKey()], $survivors,
            'the sweep took an invitation it had no business touching');
    }

    /** The sweep is reported, not silent: `remove()` hard-deletes a row it never ledgered. */
    public function test_the_swept_rows_are_previewed_counted_and_audited(): void
    {
        $created = DemoDepartment::create();

        Invitation::query()->create([
            'person_id' => $this->demoPeople($created['batch'])[0]->getKey(),
            'member_email' => 'demo-c1@'.DemoDepartment::EMAIL_DOMAIN,
            'position' => 3,
            'token_hash' => hash('sha256', Str::random(40)),
            'expires_at' => Calendar::now()->addDays(Invitation::LIFETIME_DAYS),
        ]);

        $state = DemoDepartment::removalState($created['batch']);

        $this->assertSame([['table' => 'invitations', 'count' => 1]], $state['swept'],
            'the screen must say what removal will delete that it did not create');

        $result = DemoDepartment::remove($created['batch']);

        $detail = (string) AuditLog::query()->where('action', 'demo_department_remove')->value('detail');

        $this->assertSame("batch={$created['batch']};rows={$result['rows']};swept=1", $detail);
    }

    /**
     * EVERY BLOCKING TABLE NAMES A REMEDY THAT EXISTS. Finding 2's other half: `users.person_id`
     * stays a blocker — an account is real work — but the generic *"delete or re-point them"* is
     * wrong for it in this codebase, where accounts are deactivated and never deleted. The refusal
     * has to name unbinding, which is the act that actually clears the link.
     *
     * Asserted over the WHOLE referencing set rather than on `users` alone, so a future map entry
     * cannot ship carrying a sentence that sends an operator to a control the product does not have.
     */
    public function test_every_referencing_table_names_a_remedy_the_product_actually_offers(): void
    {
        $tables = [];

        foreach (DemoReferences::MAP as $rows) {
            foreach ($rows as $row) {
                $tables[$row['table']] = true;
            }
        }

        $tables = array_keys($tables);
        sort($tables);

        $this->assertGreaterThan(5, count($tables), 'the reference map has gone missing');

        $missing = array_values(array_filter(
            $tables,
            static fn (string $table): bool => ! array_key_exists($table, DemoReferences::REMEDIES),
        ));

        $this->assertSame([], $missing,
            "These tables can block a removal and the refusal has no remedy for them, so it falls\n"
            ."back on prose that may name a control this product does not offer.\n"
            .implode("\n", $missing));
    }

    public function test_the_account_refusal_names_unbinding_rather_than_deleting(): void
    {
        $created = DemoDepartment::create();

        User::create([
            'member_name' => 'claimed', 'password' => 'Str0ng-pass!x', 'active' => true,
            'person_id' => $this->demoPeople($created['batch'])[0]->getKey(),
        ]);

        try {
            DemoDepartment::remove($created['batch']);
            $this->fail('An account claimed against a demo person still refuses the removal.');
        } catch (DemoRemovalBlockedException $e) {
            $message = $e->getMessage();

            $this->assertStringContainsString('unbind', strtolower($message),
                'accounts are deactivated and never deleted here, so "delete them" is not a remedy');
            $this->assertStringNotContainsString('demo.invalid', $message);
        }
    }

    // --- The gap between the check and the delete --------------------------------------------------

    /**
     * THE PRE-FLIGHT MUST RUN INSIDE THE TRANSACTION THAT DELETES, and this is the test that says so
     * (P1e-2 adversarial review, finding 1).
     *
     * A check that runs outside the transaction it guards decides against a world that no longer
     * exists by the time the first `DELETE` runs. And the database is NOT a backstop here the way it
     * is for `PeriodController::destroy()`: of the nineteen inbound keys in `DemoReferences::MAP`
     * only three are `RESTRICT`. Nine are `ON DELETE SET NULL` — including all four of
     * `handover_signoffs`' `*_person_id` columns, the medico-legal names of record that
     * `DemoReferences`' own docblock says "must not be reachable from a cleanup button". So the
     * unguarded shape does not raise: it **succeeds**, writes a clean `demo_department_remove` row,
     * and leaves an attestation naming nobody.
     *
     * `TransactionBeginning` is the seam. It fires immediately after `BEGIN` and before the first
     * statement of the closure, which is exactly the window a concurrent session writes into — so a
     * blocker planted from it is invisible to any check made before the transaction opened and
     * visible to any check made inside it. The one-shot guard matters: `AuditLog::record()` opens a
     * transaction of its own, and `HandoverSignoff::create()` may too.
     *
     * WHAT THIS CAN AND CANNOT PROVE ON SQLITE. It proves the re-check happens inside. It does NOT
     * prove the lock, and structurally cannot: `SQLiteGrammar::compileLock()` returns the empty
     * string, so `lockForUpdate()` is a no-op on this connection, and SQLite serialises writers
     * anyway. On MySQL 8.4 under REPEATABLE READ the gap is wider than this test can even express —
     * a `DELETE` is a *current* read and sees rows committed after the transaction's snapshot, while
     * a plain `SELECT` beside it does not — which is why the lock is asserted at source level below
     * rather than left to a suite that has never run against InnoDB.
     */
    public function test_a_blocker_that_lands_after_the_preflight_still_refuses_the_whole_removal(): void
    {
        $created = DemoDepartment::create();
        $person = $this->demoPeople($created['batch'])[1];
        $rows = DemoLedger::rowsFor($created['batch']);

        // Clear at the moment the operator confirms — which is the whole premise.
        $this->assertSame([], DemoDepartment::preflight($created['batch']));

        $planted = false;

        Event::listen(TransactionBeginning::class, function () use ($person, &$planted): void {
            if ($planted) {
                return;
            }

            $planted = true;

            HandoverSignoff::create([
                'unit_id' => Unit::findByCode('WARD')?->getKey(),
                'handover_date' => Calendar::todayYmd(),
                'endorsed_by_person_id' => $person->getKey(),
                'endorsed_by_name' => 'Demo Chief Resident One',
            ]);
        });

        try {
            DemoDepartment::remove($created['batch']);
            $this->fail('A blocker that landed after the pre-flight must still refuse the removal.');
        } catch (DemoRemovalBlockedException $e) {
            $this->assertSame(['handover_signoffs' => 1], $this->blockedByTable($e));
        }

        $this->assertTrue($planted, 'the seam never fired, so this test proved nothing');

        // Refused WHOLE: every ledgered row is still there and the ledger still names the batch.
        $survivors = [];

        foreach ($rows as $row) {
            if (! DB::table($row['table'])->where('id', $row['id'])->exists()) {
                $survivors[] = $row['table'].' '.$row['id'];
            }
        }

        $this->assertSame([], $survivors, 'a refused removal deleted these rows: '.implode(', ', $survivors));
        $this->assertTrue(DemoLedger::has());

        // THE HARM, STATED AS AN ASSERTION. Without the in-transaction re-check the removal commits
        // and takes the sign-off's name of record with it — the row survives with
        // `endorsed_by_person_id` NULLed by `ON DELETE SET NULL`, and nothing anywhere reports it.
        // With the re-check the whole transaction unwinds, so the planted row is gone too and this
        // reads zero for the right reason.
        $this->assertSame(0, DB::table('handover_signoffs')->whereNull('endorsed_by_person_id')->count(),
            'a sign-off was left in the database with its medico-legal name of record NULLed');
    }

    /**
     * THE LOCK, AND THE HOISTED CATALOGUE READ — asserted at SOURCE level, deliberately, because
     * neither is observable on the connection this suite runs against.
     *
     * A plain re-read inside the transaction is sufficient on SQLite and NOT on MySQL: under
     * REPEATABLE READ a non-locking `SELECT` reads the transaction's snapshot while the `DELETE`
     * beside it reads current rows, so the re-check can come back clean against a row the delete is
     * about to orphan. InnoDB's shared lock on the parent row is what blocks a concurrent session
     * inserting a child that points at it — the same idiom `AuditLog::record()` and
     * `InvitationAcceptController` already use, and for the same reason.
     *
     * `Schema::hasTable()` compiles to an uncached `information_schema` query on MySQL
     * (`MySqlGrammar::compileTableExists()`), so a loop of them inside the transaction body is one
     * catalogue round trip per ledgered row spent holding locks open for nothing.
     */
    public function test_the_recheck_locks_and_the_catalogue_reads_happen_before_the_transaction(): void
    {
        $method = new ReflectionMethod(DemoDepartment::class, 'remove');
        $lines = file((string) $method->getFileName());
        $body = implode('', array_slice(
            (array) $lines,
            $method->getStartLine() - 1,
            $method->getEndLine() - $method->getStartLine() + 1,
        ));

        $transaction = strpos($body, 'DB::transaction(');
        $this->assertIsInt($transaction, 'remove() no longer opens a transaction at all');

        $hasTable = strpos($body, 'Schema::hasTable(');
        $this->assertIsInt($hasTable, 'remove() no longer asks whether a ledgered table exists');
        $this->assertLessThan($transaction, $hasTable,
            'Schema::hasTable() is an uncached information_schema query on MySQL; asking it once per '
            .'ledgered row from inside the transaction widens the window for nothing.');

        foreach (['self::preflight(', 'self::lockLedgeredRows('] as $needle) {
            $at = strpos($body, $needle, $transaction);

            $this->assertIsInt($at,
                "remove() does not call {$needle} inside its transaction. A check made outside the "
                .'transaction it guards decides against a world that no longer exists, and only three '
                .'of the nineteen inbound keys are RESTRICT — the rest SET NULL or CASCADE silently.');
        }

        // And the locker really locks. Split out so the two claims fail separately: one is "the
        // re-check happens under a lock", the other is "the lock is a lock".
        $locker = new ReflectionMethod(DemoDepartment::class, 'lockLedgeredRows');
        $lockerBody = implode('', array_slice(
            (array) $lines,
            $locker->getStartLine() - 1,
            $locker->getEndLine() - $locker->getStartLine() + 1,
        ));

        $this->assertStringContainsString('lockForUpdate(', $lockerBody,
            'lockLedgeredRows() takes no lock, so on InnoDB the re-check reads a snapshot the '
            .'DELETE beside it does not.');
    }

    // --- Audit -----------------------------------------------------------------------------------

    public function test_the_refusal_is_audited_and_the_removal_is_audited_and_they_are_different_actions(): void
    {
        $created = DemoDepartment::create();

        $handover = Handover::create([
            'unit_id' => $this->demoUnit()->getKey(),
            'handover_date' => Calendar::todayYmd(),
            'bed' => '1', 'mrn' => 'M-1', 'patient_name' => 'A Child',
        ]);

        try {
            DemoDepartment::remove($created['batch']);
        } catch (DemoRemovalBlockedException) {
            // Expected — the refusal is the subject of the first half.
        }

        $refused = AuditLog::query()->where('action', 'demo_department_remove_refused')->get();

        $this->assertCount(1, $refused);
        $this->assertSame("batch={$created['batch']};blocked=handovers:1", (string) $refused->first()->detail);
        $this->assertSame(0, AuditLog::query()->where('action', 'demo_department_remove')->count());

        // Clear the real row and try again: a DIFFERENT action, and still exactly one row each.
        $handover->forceDelete();

        $result = DemoDepartment::remove($created['batch']);

        $this->assertCount(1, AuditLog::query()->where('action', 'demo_department_remove_refused')->get());

        $removed = AuditLog::query()->where('action', 'demo_department_remove')->get();

        $this->assertCount(1, $removed);

        // `swept=` is always present, never appended only when non-zero: one shape per action, and
        // a reader of the trail should not have to infer that its absence means nothing was swept.
        $this->assertSame(
            "batch={$created['batch']};rows={$result['rows']};swept=0",
            (string) $removed->first()->detail,
        );
    }

    /** ONE summary row, never one per deleted row (invariant 12 / P1 finding 11). */
    public function test_removal_writes_exactly_one_audit_row_for_hundreds_of_deletions(): void
    {
        $created = DemoDepartment::create();

        $before = AuditLog::query()->count();

        $result = DemoDepartment::remove($created['batch']);

        $this->assertGreaterThan(5, $result['rows'], 'The demo is many rows, which is the point of this test.');
        $this->assertSame($before + 1, AuditLog::query()->count());
    }

    // --- The reference map -----------------------------------------------------------------------

    /**
     * @return list<array{table:string, column:string, references:string}> every foreign key in the
     *                                                                    live schema that points at
     *                                                                    a table the demo writes
     */
    private function inboundForeignKeys(): array
    {
        $found = [];

        foreach (Schema::getTableListing() as $qualified) {
            $table = TableCounts::bare($qualified);

            foreach (Schema::getForeignKeys($table) as $foreignKey) {
                $target = TableCounts::bare((string) $foreignKey['foreign_table']);

                if (! array_key_exists($target, DemoReferences::MAP)) {
                    continue;
                }

                foreach ((array) $foreignKey['columns'] as $column) {
                    $found[] = ['table' => $table, 'column' => (string) $column, 'references' => $target];
                }
            }
        }

        usort($found, static fn (array $a, array $b): int => [$a['references'], $a['table'], $a['column']]
            <=> [$b['references'], $b['table'], $b['column']]);

        return $found;
    }

    /** @return list<array{table:string, column:string, references:string}> */
    private function mappedReferences(): array
    {
        $mapped = [];

        foreach (DemoReferences::MAP as $target => $rows) {
            foreach ($rows as $row) {
                $mapped[] = ['table' => $row['table'], 'column' => $row['column'], 'references' => $target];
            }
        }

        usort($mapped, static fn (array $a, array $b): int => [$a['references'], $a['table'], $a['column']]
            <=> [$b['references'], $b['table'], $b['column']]);

        return $mapped;
    }

    /**
     * DERIVED FROM THE LIVE SCHEMA, not from the constant it guards — which is what makes the map
     * survive a migration written six months from now. A foreign key the map has never heard of is
     * a path by which a real row can lean on a demo row unnoticed, and removal would take it.
     */
    public function test_every_foreign_key_pointing_at_a_demo_table_is_in_the_reference_map(): void
    {
        $inbound = $this->inboundForeignKeys();

        // A floor first: introspection returning nothing would make the sweep vacuously green.
        $this->assertGreaterThan(10, count($inbound), 'Foreign-key introspection returned almost nothing.');

        $missing = [];

        foreach ($inbound as $foreignKey) {
            if (! in_array($foreignKey, $this->mappedReferences(), true)) {
                $missing[] = $foreignKey['table'].'.'.$foreignKey['column'].' -> '.$foreignKey['references'];
            }
        }

        $this->assertSame([], $missing,
            "DemoReferences::MAP does not know about these foreign keys, so removal cannot see a\n"
            ."real row leaning on a demo one through them.\n".implode("\n", $missing));
    }

    /** The other direction: a map entry naming a key that no longer exists is a check that never fires. */
    public function test_the_reference_map_names_only_real_foreign_keys(): void
    {
        $inbound = $this->inboundForeignKeys();
        $stale = [];

        foreach ($this->mappedReferences() as $mapped) {
            if (! in_array($mapped, $inbound, true)) {
                $stale[] = $mapped['table'].'.'.$mapped['column'].' -> '.$mapped['references'];
            }
        }

        $this->assertSame([], $stale, "Stale entries in DemoReferences::MAP:\n".implode("\n", $stale));
    }

    /**
     * The map's KEYS are the tables the demo writes, and this asks the creator rather than the
     * constant. A ninth table added to `create()` without a map entry is a table whose inbound
     * references nobody checks.
     */
    public function test_the_reference_map_covers_every_table_the_demo_actually_creates(): void
    {
        $created = DemoDepartment::create();

        $tables = array_values(array_unique(array_map(
            static fn (array $row): string => $row['table'],
            DemoLedger::rowsFor($created['batch']),
        )));

        sort($tables);

        $this->assertSame([], array_values(array_diff($tables, array_keys(DemoReferences::MAP))),
            'The demo creates rows in a table DemoReferences::MAP has never heard of.');
    }
}
