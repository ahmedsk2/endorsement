<?php

namespace Tests\Feature\Demo;

use App\Models\AuditLog;
use App\Models\Clinic;
use App\Models\Institution;
use App\Models\Level;
use App\Models\Period;
use App\Models\Person;
use App\Models\Unit;
use App\Models\User;
use App\Support\Calendar;
use App\Support\Clinics\ClinicRoster;
use App\Support\Demo\DemoDepartment;
use App\Support\Demo\DemoLedger;
use Database\Seeders\AccessControlSeeder;
use Database\Seeders\ReferenceSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use InvalidArgumentException;
use RuntimeException;
use Tests\Support\TableCounts;
use Tests\TestCase;

/**
 * Munawib ST-05's creation half (P1e Decision F, Task 12): one transaction, one batch, and EVERY
 * row ledgered.
 *
 * THE OWNER RULED (2026-08-11) THAT THIS MAY RUN ON THE LIVE INSTANCE, which is what makes the
 * assertions below load-bearing rather than tidy. `DemoSeeder` and `E2eSeeder` both throw outright
 * in production and are right to: neither marks a single row, so a row either creates is
 * indistinguishable from a real one forever. This one is safe in the same place for exactly two
 * reasons, and both are asserted here rather than asserted about:
 *
 *   1. Every row it writes is in the ledger, so removal can prove it removed everything
 *      (`test_every_row_it_creates_is_in_the_ledger`, compared as a WHOLE per-table map derived
 *      from the live schema — never a hand-written list of tables, which goes stale on the next
 *      migration while the test keeps passing).
 *   2. It mints NO ACCOUNT. A roster-only person has no `users` row and therefore cannot
 *      authenticate BY CONSTRUCTION (P0c), so pressing this button on a live instance holding
 *      children's PHI creates no working login — which is the specific harm `DemoSeeder`'s throw
 *      exists to prevent. `test_it_creates_no_account_and_therefore_no_way_in` pins it.
 *
 * THE FIXTURE IS SYNTHETIC AND STAYS THAT WAY (CLAUDE.md), and this one is the most visible fixture
 * in the codebase because it ships inside the product rather than beside the tests. Every name
 * carries the demo prefix and every address sits on a domain the DNS root guarantees can never
 * resolve — the label a ledger cannot provide, because a CSV export and a printed sheet do no
 * ledger lookup.
 */
class DemoCreateTest extends TestCase
{
    use RefreshDatabase;

    /**
     * The two tables a create is EXPECTED to grow outside the ledger, and no others.
     *
     * `audit_log` is append-only and hash-chained, and one summary row per operation is the
     * bulk-operation discipline (invariant 12), not leakage.
     *
     * `demo_rows` IS the ledger. A ledger entry recording the ledger entry would be an infinite
     * regress, so its own growth is bookkeeping about the rows rather than one of them. Its return
     * to the pre-seed count is proved separately, by Task 13's round trip, which compares
     * before-create against after-remove and does NOT excuse this table.
     */
    private const NOT_LEDGERED = ['audit_log', 'demo_rows'];

    protected function setUp(): void
    {
        parent::setUp();

        // The institution row (provenance), the four seeded units and the training ladder. The
        // demo attaches level spans, so an empty ladder is a different code path — exercised
        // separately below rather than by accident here.
        $this->seed(ReferenceSeeder::class);
        $this->seed(AccessControlSeeder::class);
    }

    /**
     * The per-table delta a create produced, minus the tables it is expected to grow outside the
     * ledger. `Tests\Support\TableCounts` derives the table list from the live schema and compares
     * the map whole, which is what keeps this honest across engines and across migrations.
     *
     * @param  array<string, int>  $before
     * @param  array<string, int>  $after
     * @return array<string, int>
     */
    private function delta(array $before, array $after): array
    {
        $delta = TableCounts::delta($before, $after);

        foreach (self::NOT_LEDGERED as $table) {
            unset($delta[$table]);
        }

        return $delta;
    }

    /** @return array<string, int> what the ledger claims was created, per table. */
    private function ledgerCounts(string $batch): array
    {
        $counts = [];

        foreach (DemoLedger::rowsFor($batch) as $row) {
            $counts[$row['table']] = ($counts[$row['table']] ?? 0) + 1;
        }

        ksort($counts);

        return $counts;
    }

    // --- The ledger claim ------------------------------------------------------------------------

    /**
     * THE CENTRAL CLAIM, and the reason the comparison is a whole-map `assertSame` rather than a
     * per-table loop: a row created in a table nobody thought to name is invisible to any
     * enumeration written by hand, and that row is precisely the one that survives removal forever.
     * The table list is derived from the live schema on both sides.
     */
    public function test_every_row_it_creates_is_in_the_ledger(): void
    {
        $before = TableCounts::snapshot();

        $result = DemoDepartment::create();

        $after = TableCounts::snapshot();

        $this->assertSame(
            $this->delta($before, $after),
            $this->ledgerCounts($result['batch']),
            'Every row the demo creates must be ledgered. A table appearing on the left and not '
            .'the right is a row that would survive removal, in a live instance, forever.',
        );

        // And the reported count is the ledger's own size, not a number the creator kept in its head.
        $this->assertSame(count(DemoLedger::rowsFor($result['batch'])), $result['rows']);
        $this->assertGreaterThan(0, $result['rows']);
    }

    /** The batch is a real UUID, minted by the creator — `person_levels.promotion_batch_id`'s precedent. */
    public function test_it_returns_one_batch_id_and_every_ledger_row_carries_it(): void
    {
        $result = DemoDepartment::create();

        $this->assertMatchesRegularExpression(
            '/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/',
            $result['batch'],
        );

        $this->assertSame([$result['batch']], DemoLedger::batches());
    }

    // --- Running twice ---------------------------------------------------------------------------

    /**
     * The remedy is NAMED in the refusal. "Already exists" with no next step is the shape that
     * sends an administrator to a database console, which is where the unledgered rows come from.
     */
    public function test_it_refuses_to_run_twice_and_names_the_remedy(): void
    {
        DemoDepartment::create();

        $before = TableCounts::snapshot();

        try {
            DemoDepartment::create();
            $this->fail('A second demo department must be refused.');
        } catch (InvalidArgumentException $e) {
            $this->assertStringContainsString('remove', strtolower($e->getMessage()));
        }

        $this->assertSame($before, TableCounts::snapshot(), 'A refused create writes nothing at all.');
        $this->assertCount(1, DemoLedger::batches());
    }

    // --- One transaction -------------------------------------------------------------------------

    /**
     * FORCED FAILURE AT THE FAR END OF THE SEQUENCE — the clinic is the last thing built, so a
     * throw there has the unit, the people, their level spans, the periods, the rota and the leave
     * already written and already ledgered. Zero of them may survive.
     *
     * The `Model::creating()` plant is this suite's existing idiom for exactly this
     * (`InvitationBulkResendTest::…the_second_mint_fails`); model event listeners do not leak
     * between tests because each test builds a fresh application, and with it a fresh dispatcher.
     */
    public function test_it_runs_in_one_transaction_and_a_failure_leaves_nothing_behind(): void
    {
        $before = TableCounts::snapshot();

        Clinic::creating(function (): void {
            throw new RuntimeException('planted: the clinic step fails');
        });

        try {
            DemoDepartment::create();
            $this->fail('The planted failure must propagate.');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('planted', $e->getMessage());
        }

        $this->assertSame($before, TableCounts::snapshot(), 'The whole transaction rolled back.');
        $this->assertFalse(DemoLedger::has(), 'The ledger rolled back with the rows it names.');
        $this->assertSame(0, AuditLog::query()->where('action', 'demo_department_create')->count());
    }

    // --- The human label -------------------------------------------------------------------------

    /**
     * ST-05's "clearly-labeled", and the half a ledger cannot do: a CSV export and a printed sheet
     * perform no ledger lookup, so the label has to be in the value itself.
     *
     * Asserted over the WHOLE set of created rows of each shape, not over one sample — a second
     * demo person added later without the prefix is exactly the row this catches.
     */
    public function test_every_created_row_is_visibly_labelled_as_demo(): void
    {
        $result = DemoDepartment::create();

        $unlabelled = [];

        foreach (DemoLedger::rowsFor($result['batch']) as $row) {
            $label = match ($row['table']) {
                'units' => Unit::query()->whereKey($row['id'])->value('name'),
                'people' => Person::query()->whereKey($row['id'])->value('full_name'),
                'clinics' => Clinic::query()->whereKey($row['id'])->value('name'),
                'periods' => Period::query()->whereKey($row['id'])->value('label'),
                default => null,
            };

            if (is_string($label) && ! str_starts_with($label, DemoDepartment::LABEL_PREFIX)) {
                $unlabelled[] = $row['table'].' '.$row['id'].' is named "'.$label.'"';
            }
        }

        $this->assertSame([], $unlabelled,
            'Every human-visible name the demo creates carries the demo prefix. The ledger is the '
            ."machine truth and this is the human label; neither substitutes for the other.\n"
            .implode("\n", $unlabelled));

        // The unit's own code says it too, so a rota CSV keyed on the code is labelled as well.
        $this->assertStringContainsString('DEMO', (string) Unit::findByCode(DemoDepartment::UNIT_CODE)?->code);
    }

    /**
     * CLAUDE.md's synthetic-fixtures rule, asserted rather than trusted — and the domain choice is
     * a safety property, not a naming convention. `.invalid` is reserved by RFC 2606 and guaranteed
     * never to resolve, so an invitation accidentally issued to a demo person on a LIVE instance
     * cannot be delivered to a real inbox.
     */
    public function test_the_demo_people_are_obviously_fictional_and_use_a_reserved_domain(): void
    {
        $result = DemoDepartment::create();

        $ids = array_values(array_map(
            static fn (array $row): int => $row['id'],
            array_filter(DemoLedger::rowsFor($result['batch']), static fn (array $row): bool => $row['table'] === 'people'),
        ));

        $this->assertGreaterThanOrEqual(3, count($ids), 'A demo department needs people to be a department.');

        $people = Person::query()->whereKey($ids)->get();

        foreach ($people as $person) {
            $this->assertStringStartsWith(DemoDepartment::LABEL_PREFIX, (string) $person->full_name);
            $this->assertStringEndsWith('@'.DemoDepartment::EMAIL_DOMAIN, (string) $person->email);
        }

        // The three roles a department actually hands over between (design §5.1's ladder), so the
        // sign-off pickers have something to offer.
        $positions = $people->pluck('position')->map(static fn ($p): int => (int) $p)->unique()->sort()->values()->all();
        $this->assertSame([3, 4, 5], $positions);
    }

    /**
     * THE PROPERTY THAT MAKES RUNNING THIS IN PRODUCTION SAFE. A roster-only person has no `users`
     * row and therefore cannot authenticate by construction (P0c) — that replaced a design needing
     * a gate in twelve places. `DemoSeeder` throws in production precisely because it mints
     * accounts with a password published in the repository; this one mints none at all.
     */
    public function test_it_creates_no_account_and_therefore_no_way_in(): void
    {
        $before = User::query()->withTrashed()->count();

        $result = DemoDepartment::create();

        $this->assertSame($before, User::query()->withTrashed()->count());

        $ledgeredTables = array_unique(array_map(
            static fn (array $row): string => $row['table'],
            DemoLedger::rowsFor($result['batch']),
        ));

        $this->assertNotContains('users', $ledgeredTables);
    }

    // --- The unit --------------------------------------------------------------------------------

    /**
     * `Unit::RESERVED_CODES` is enforced by a `saving` guard that THROWS, and the list is derived
     * from the registered routes by `ReservedUnitCodesTest` — so a demo unit that picked one would
     * fail at insert on a live instance, inside a transaction, with a raw exception.
     */
    public function test_it_uses_a_unit_code_that_is_not_reserved(): void
    {
        $this->assertNotContains(DemoDepartment::UNIT_CODE, Unit::RESERVED_CODES);

        DemoDepartment::create();

        $unit = Unit::findByCode(DemoDepartment::UNIT_CODE);

        $this->assertNotNull($unit);
        $this->assertTrue((bool) $unit->active);
        $this->assertTrue((bool) $unit->clinic_owner, 'The demo owns clinics, or its clinic cannot exist.');
        $this->assertTrue((bool) $unit->training_rotation, 'The demo rotates, or the rota has nowhere to put anybody.');

        // A colour from the allow-list, never a hex and never an invented class (P1b Task 3).
        $this->assertArrayHasKey((string) $unit->bar_class, Unit::BAR_CLASSES);
    }

    /** A real unit already holding the demo code is refused BEFORE anything is written. */
    public function test_it_refuses_when_the_demo_unit_code_is_already_taken(): void
    {
        Unit::create(['code' => DemoDepartment::UNIT_CODE, 'name' => 'A real unit', 'active' => true]);

        $before = TableCounts::snapshot();

        $this->expectException(InvalidArgumentException::class);

        try {
            DemoDepartment::create();
        } finally {
            $this->assertSame($before, TableCounts::snapshot());
            $this->assertFalse(DemoLedger::has());
        }
    }

    // --- The clinic ------------------------------------------------------------------------------

    /**
     * The demo must DEMONSTRATE what P1e-1 built, not merely coexist with it: a clinic whose
     * attendance resolves, today, from the rota it just wrote. A demo department whose clinic
     * resolves to nobody teaches an administrator that clinics do not work.
     */
    public function test_it_creates_a_working_clinic_that_resolves_to_the_demo_residents(): void
    {
        $result = DemoDepartment::create();

        $unit = Unit::findByCode(DemoDepartment::UNIT_CODE);
        $this->assertNotNull($unit);

        $clinic = Clinic::query()->where('unit_id', $unit->getKey())->firstOrFail();

        $resolved = ClinicRoster::forDate($clinic, Calendar::todayYmd());

        $this->assertNotSame([], $resolved, 'The demo clinic resolves to nobody, which teaches the opposite of the truth.');

        foreach ($resolved as $row) {
            $this->assertStringStartsWith(DemoDepartment::LABEL_PREFIX, (string) $row['full_name']);
            // CL-05's contact-free contract: a withheld field is ABSENT, never null.
            $this->assertArrayNotHasKey('email', $row);
            $this->assertArrayNotHasKey('phone', $row);
        }

        $this->assertSame($result['batch'], DemoLedger::batches()[0]);
    }

    // --- Periods ---------------------------------------------------------------------------------

    /**
     * "Never a second year alongside a real one" (Decision F / Task 12). A department that has
     * already generated its academic year keeps it, and the demo says so in the result rather than
     * silently doing something different from what it did last time.
     */
    public function test_it_leaves_an_existing_academic_year_alone_and_says_so(): void
    {
        $today = Calendar::todayYmd();

        Period::create([
            'academic_year' => '2099-2100', 'kind' => Period::WEEK_BLOCK, 'position' => 1,
            'label' => 'Real Block 1',
            'starts_on' => Calendar::addDays($today, -7)->format(Calendar::YMD),
            'ends_on' => Calendar::addDays($today, 21)->format(Calendar::YMD),
        ]);

        $result = DemoDepartment::create();

        $this->assertSame(1, Period::query()->count(), 'No second academic year was generated.');
        $this->assertNotSame([], $result['skipped']);
        $this->assertStringContainsString('period', strtolower(implode(' ', $result['skipped'])));

        // And the rota it wrote landed in the department's OWN period, so the clinic still resolves.
        $clinic = Clinic::query()->firstOrFail();
        $this->assertNotSame([], ClinicRoster::forDate($clinic, $today));
    }

    /** With no periods at all, the demo generates its own — clearly labelled, and covering today. */
    public function test_it_generates_its_own_periods_when_the_department_has_none(): void
    {
        $result = DemoDepartment::create();

        $periods = Period::query()->orderBy('starts_on')->get();

        $this->assertGreaterThanOrEqual(2, $periods->count());

        foreach ($periods as $period) {
            $this->assertStringStartsWith(DemoDepartment::LABEL_PREFIX, (string) $period->label);
        }

        $this->assertNotNull(Calendar::periodFor(Calendar::todayYmd()));
        $this->assertSame([], $result['skipped'], 'Nothing was skipped, so there is nothing to report.');
    }

    // --- Audit -----------------------------------------------------------------------------------

    /**
     * ONE summary row per operation, never one per row (invariant 12): a demo department is
     * hundreds of rows and `AuditLog::record()` locks the chain tail inside its own transaction, so
     * N of them serialise the whole trail (P1 finding 11).
     *
     * Ids and counts only — never a person's name, a unit's name or a clinic's name (invariant 9).
     */
    public function test_it_writes_one_audit_row_naming_the_batch_and_the_count_and_no_name(): void
    {
        $result = DemoDepartment::create();

        $rows = AuditLog::query()->where('action', 'demo_department_create')->get();

        $this->assertCount(1, $rows);

        $detail = (string) $rows->first()->detail;

        $this->assertSame("batch={$result['batch']};rows={$result['rows']}", $detail);

        foreach ([DemoDepartment::LABEL_PREFIX, DemoDepartment::EMAIL_DOMAIN, DemoDepartment::UNIT_CODE] as $name) {
            $this->assertStringNotContainsString($name, $detail);
        }
    }

    /** The actor is carried through when there is one, and absent when the console ran it. */
    public function test_the_audit_row_names_the_actor_when_one_acted(): void
    {
        $institution = Institution::query()->firstOrFail();

        $person = Person::create([
            'full_name' => 'Real Administrator', 'position' => 0, 'active' => true,
            'email' => 'real-admin@example.org', 'institution_id' => $institution->getKey(),
        ]);

        $user = User::create([
            'member_name' => 'realadmin', 'password' => 'Str0ng-pass!x', 'active' => true,
            'person_id' => $person->getKey(), 'institution_id' => $institution->getKey(),
        ]);

        DemoDepartment::create($institution->getKey(), $user->getKey(), '203.0.113.9');

        $row = AuditLog::query()->where('action', 'demo_department_create')->firstOrFail();

        $this->assertSame($user->getKey(), $row->user_id);
        $this->assertSame('203.0.113.9', $row->ip);
    }

    // --- Provenance ------------------------------------------------------------------------------

    /**
     * D11: `institution_id` is provenance and in-instance grouping. It is stamped on every row that
     * carries the column and is never used to find one — the demo is found through its ledger.
     */
    public function test_the_rows_that_carry_provenance_carry_the_departments_own(): void
    {
        $institution = Institution::query()->firstOrFail();

        $result = DemoDepartment::create();

        foreach (DemoLedger::rowsFor($result['batch']) as $row) {
            if (! Schema::hasColumn($row['table'], 'institution_id')) {
                continue;
            }

            $this->assertSame(
                $institution->getKey(),
                DB::table($row['table'])->where('id', $row['id'])->value('institution_id'),
                $row['table'].' '.$row['id'].' carries no provenance.',
            );
        }
    }

    /** An empty training ladder is a real state on a fresh instance, and must not be a 500. */
    public function test_it_still_builds_a_department_when_the_level_ladder_is_empty(): void
    {
        Level::query()->delete();

        $result = DemoDepartment::create();

        $this->assertNotSame([], $result['skipped']);
        $this->assertNotNull(Unit::findByCode(DemoDepartment::UNIT_CODE));

        $clinic = Clinic::query()->firstOrFail();
        $this->assertNotSame([], ClinicRoster::forDate($clinic, Calendar::todayYmd()));
    }
}
