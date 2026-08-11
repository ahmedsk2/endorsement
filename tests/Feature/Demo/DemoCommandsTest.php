<?php

namespace Tests\Feature\Demo;

use App\Models\AuditLog;
use App\Models\Handover;
use App\Models\Unit;
use App\Support\Calendar;
use App\Support\Demo\DemoDepartment;
use App\Support\Demo\DemoLedger;
use Database\Seeders\AccessControlSeeder;
use Database\Seeders\ReferenceSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\TableCounts;
use Tests\TestCase;

/**
 * ST-05's *"and for development fixtures"* half (P1e Task 13): two thin console commands over
 * `DemoDepartment::create()` and `::remove()`.
 *
 * THIN IS THE REQUIREMENT, NOT A STYLE PREFERENCE. Everything that decides anything — the
 * refuse-to-run-twice check, the reference pre-flight, the transaction, the audit rows — lives in
 * the support class, so the console and the screen (Task 14) cannot diverge into two behaviours
 * with one name. These commands confirm, print and delegate.
 *
 * NEITHER CARRIES AN ENVIRONMENT GUARD, unlike `DemoSeeder` and `E2eSeeder` (Decision F): those two
 * mark nothing and can never be removed, so a production run is permanent; this one is ledgered and
 * provably removable, and ST-05's "for training" means the live instance, because that is where
 * people are trained.
 *
 * BOTH CONFIRM UNLESS `--force`. `demo:remove` is a hard delete with no undo anywhere in the
 * product, so it shows the pre-flight BEFORE it asks — the preview-then-confirm shape invariant 12
 * requires, in a terminal.
 */
class DemoCommandsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(ReferenceSeeder::class);
        $this->seed(AccessControlSeeder::class);
    }

    public function test_the_seed_command_creates_one_demo_department(): void
    {
        $this->artisan('demo:seed --force')->assertExitCode(0);

        $this->assertNotNull(Unit::findByCode(DemoDepartment::UNIT_CODE));
        $this->assertCount(1, DemoLedger::batches());
        $this->assertSame(1, AuditLog::query()->where('action', 'demo_department_create')->count());
    }

    /** Without `--force` it asks, and a "no" leaves the department exactly as it was. */
    public function test_declining_the_confirmation_creates_nothing(): void
    {
        $before = TableCounts::snapshot();

        $this->artisan('demo:seed')
            ->expectsConfirmation('Create a clearly-labelled demo department now?', 'no')
            ->assertExitCode(0);

        $this->assertSame($before, TableCounts::snapshot());
        $this->assertFalse(DemoLedger::has());
    }

    public function test_the_seed_command_refuses_a_second_department(): void
    {
        $this->artisan('demo:seed --force')->assertExitCode(0);

        $this->artisan('demo:seed --force')->assertExitCode(1);

        $this->assertCount(1, DemoLedger::batches());
    }

    public function test_the_remove_command_removes_the_department(): void
    {
        $this->artisan('demo:seed --force')->assertExitCode(0);

        $this->artisan('demo:remove --force')->assertExitCode(0);

        $this->assertFalse(DemoLedger::has());
        $this->assertNull(Unit::findByCode(DemoDepartment::UNIT_CODE));
        $this->assertSame(1, AuditLog::query()->where('action', 'demo_department_remove')->count());
    }

    public function test_the_remove_command_refuses_when_nothing_was_ever_created(): void
    {
        $this->artisan('demo:remove --force')->assertExitCode(1);
    }

    /**
     * The refusal reaches the operator as a readable report with the remedy in it, and NOTHING is
     * deleted — the console path is held to exactly the same "refuse whole" contract as the screen.
     */
    public function test_the_remove_command_reports_the_preflight_and_deletes_nothing(): void
    {
        $this->artisan('demo:seed --force')->assertExitCode(0);

        Handover::create([
            'unit_id' => Unit::findByCode(DemoDepartment::UNIT_CODE)?->getKey(),
            'handover_date' => Calendar::todayYmd(),
            'bed' => '1', 'mrn' => 'M-1', 'patient_name' => 'A Child',
        ]);

        $before = TableCounts::snapshot();

        $this->artisan('demo:remove --force')
            ->expectsOutputToContain('handovers')
            ->assertExitCode(1);

        unset($before[TableCounts::qualify('audit_log')]);
        $after = TableCounts::snapshot();
        unset($after[TableCounts::qualify('audit_log')]);

        $this->assertSame($before, $after);
        $this->assertTrue(DemoLedger::has());
    }
}
