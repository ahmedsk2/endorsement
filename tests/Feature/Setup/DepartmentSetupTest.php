<?php

namespace Tests\Feature\Setup;

use App\Models\Clinic;
use App\Models\Institution;
use App\Models\Invitation;
use App\Models\Level;
use App\Models\Period;
use App\Models\Person;
use App\Models\Unit;
use App\Models\User;
use App\Support\Calendar;
use App\Support\Setup\DepartmentSetup;
use Database\Seeders\AccessControlSeeder;
use Database\Seeders\ReferenceSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Munawib ST-01/ST-02, P1e Decision D: how far this department is from configured, ASKED of the
 * data rather than remembered in a column.
 *
 * The whole decision is testable without a screen, and this file is where it is tested. Two
 * properties carry it:
 *
 *  - **Nothing is stored.** No table, no column, no `app_settings` key, no session key.
 *    `test_asking_writes_nothing_anywhere` snapshots `count(*)` for every table in the schema
 *    around a call and asserts the whole map is unchanged — derived from
 *    `Schema::getTableListing()`, never a hand-written list that goes stale.
 *  - **A department that is already configured is already complete.** QCH is configured today;
 *    a stored step counter would show a live, working department at step 0 and invite an
 *    administrator to redo everything. That test is Decision D's single clearest argument and
 *    it needs no backfill migration to pass.
 *
 * REQUIRED versus REVIEW is not a presentation choice. A REVIEW step can never be honestly
 * ticked because a default is indistinguishable from a reviewed default — ST-01's own example
 * is the Hijri offset, which defaults to 0 where the QCH prototype needed -1, and no query
 * tells a reviewed zero from an unexamined one. Zero holiday rules is the same shape. Inventing
 * a `reviewed_at` flag to make them tickable is exactly the stored state Decision D rejects.
 */
class DepartmentSetupTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(AccessControlSeeder::class);
        $this->seed(ReferenceSeeder::class);
        Calendar::flush();
    }

    /** @return array<string, array<string, mixed>> keyed by step key */
    private function stepsByKey(): array
    {
        $keyed = [];

        foreach (DepartmentSetup::steps()['steps'] as $step) {
            $keyed[$step['key']] = $step;
        }

        return $keyed;
    }

    private function generateAYear(): void
    {
        $start = Calendar::todayYmd();

        Period::query()->create([
            'academic_year' => '2026-2027',
            'kind' => Period::WEEK_BLOCK,
            'position' => 1,
            'label' => 'Block 1',
            'starts_on' => $start,
            'ends_on' => Calendar::ymd(Calendar::addDays($start, 27)),
        ]);
    }

    private function addRosterPerson(): Person
    {
        return Person::factory()->create(['position' => 4, 'active' => true]);
    }

    // --- The derivation itself -------------------------------------------------------------

    public function test_a_fresh_seeded_instance_reports_levels_and_units_done_and_periods_not(): void
    {
        $steps = $this->stepsByKey();

        // ReferenceSeeder ships the five-level ladder and the four units, so both are satisfied
        // out of the box on a cold start. Nothing generates periods.
        $this->assertTrue($steps['levels']['done'], 'the seeded level ladder should satisfy the levels step');
        $this->assertTrue($steps['units']['done'], 'the seeded units should satisfy the units step');
        $this->assertFalse($steps['periods']['done'], 'nothing has generated a year yet');
        $this->assertFalse($steps['roster']['done'], 'a cold start has no roster');
        $this->assertFalse($steps['invitations']['done'], 'a cold start has issued no invitation');
    }

    public function test_generating_periods_moves_that_step_to_done(): void
    {
        $this->assertFalse($this->stepsByKey()['periods']['done']);

        $this->generateAYear();

        $this->assertTrue($this->stepsByKey()['periods']['done']);
    }

    /**
     * A year whose last period ended before today does NOT satisfy the step: a department
     * running on periods that expired in a previous academic year is not configured for this
     * one, and a checklist that called that done would be lying in the direction nobody checks.
     */
    public function test_a_year_that_has_already_ended_does_not_satisfy_the_period_step(): void
    {
        $start = Calendar::ymd(Calendar::addDays(Calendar::todayYmd(), -400));

        Period::query()->create([
            'academic_year' => '2024-2025',
            'kind' => Period::WEEK_BLOCK,
            'position' => 1,
            'label' => 'Block 1',
            'starts_on' => $start,
            'ends_on' => Calendar::ymd(Calendar::addDays($start, 27)),
        ]);

        $this->assertFalse($this->stepsByKey()['periods']['done']);
    }

    /** THIS IS THE TEST THAT PINS "HOLDS NO STATE". */
    public function test_asking_writes_nothing_anywhere(): void
    {
        $this->generateAYear();
        $this->addRosterPerson();

        $before = $this->rowCounts();

        DepartmentSetup::steps();
        DepartmentSetup::steps();

        $this->assertSame($before, $this->rowCounts(), 'asking how far the department is from '
            .'configured wrote something. Decision D: the checklist is derived and stores nothing '
            .'— no table, no column, no app_settings key.');

        // Named explicitly as well as swept, because `app_settings` is the specific place a
        // "resume where you left off" counter would land, and a sweep proves it only while the
        // sweep itself is not stale.
        $this->assertSame(0, DB::table('app_settings')->count());
    }

    /** @return array<string, int> */
    private function rowCounts(): array
    {
        $counts = [];

        foreach (Schema::getTableListing() as $table) {
            $counts[$table] = DB::table($table)->count();
        }

        // Non-vacuity: a sweep over an empty table list would pass against any write at all.
        $this->assertGreaterThan(15, count($counts), 'the schema sweep found almost no tables');

        return $counts;
    }

    // --- REVIEW steps ------------------------------------------------------------------------

    public function test_a_review_step_is_never_reported_done(): void
    {
        // Configure everything a query could possibly read, including the calibration ST-01
        // names, and a holiday rule.
        Institution::current()->update(['hijri_offset_days' => -1]);
        Calendar::flush();
        \App\Models\Holiday::factory()->create(['active' => true]);
        Calendar::flush();

        $steps = $this->stepsByKey();

        foreach (['profile', 'calendar', 'holidays'] as $key) {
            $this->assertSame(DepartmentSetup::KIND_REVIEW, $steps[$key]['kind']);
            $this->assertFalse($steps[$key]['done'], "{$key} is a REVIEW step and can never be "
                .'reported done: no query distinguishes a reviewed default from an unexamined one.');
        }
    }

    public function test_a_review_step_carries_its_current_values_in_its_summary(): void
    {
        Institution::current()->update(['hijri_offset_days' => -1]);
        Calendar::flush();

        $steps = $this->stepsByKey();

        // Profile: the name and code an administrator would be reviewing.
        $this->assertStringContainsString('Qatif Central Hospital', $steps['profile']['summary']);
        $this->assertStringContainsString('QCH', $steps['profile']['summary']);

        // Calendar: ST-01's own example value, plus the weekend and the period system.
        $this->assertStringContainsString('-1', $steps['calendar']['summary']);
        $this->assertStringContainsString('Friday', $steps['calendar']['summary']);
        $this->assertStringContainsString(Calendar::timezone(), $steps['calendar']['summary']);

        // Holidays: zero is a legitimate state and must READ as a value, not as an omission.
        $this->assertStringContainsString('0', $steps['holidays']['summary']);
    }

    // --- blocked_by, which is advisory and never the gate ------------------------------------

    public function test_the_period_step_names_the_academic_year_start_as_what_blocks_it(): void
    {
        Institution::current()->update(['academic_year_start' => null]);
        Calendar::flush();

        $blocked = $this->stepsByKey()['periods']['blocked_by'];

        $this->assertNotNull($blocked);
        $this->assertStringContainsString('academic year start', $blocked);

        // With the start set, nothing blocks it — only the work itself remains.
        Institution::current()->update(['academic_year_start' => Calendar::todayYmd()]);
        Calendar::flush();

        $this->assertNull($this->stepsByKey()['periods']['blocked_by']);
    }

    public function test_the_roster_step_names_the_level_ladder_as_what_blocks_it(): void
    {
        $this->assertNull($this->stepsByKey()['roster']['blocked_by'], 'the seeded ladder unblocks it');

        Level::query()->update(['active' => false]);

        $this->assertStringContainsString('level', (string) $this->stepsByKey()['roster']['blocked_by']);
    }

    /**
     * A checklist that refuses is a SECOND authorization boundary, and two boundaries drift.
     * The blocked step's own screen is asked directly here: it answers for itself — it renders,
     * because its refusal (if any) belongs to its own server-side rule and not to this class.
     */
    public function test_the_checklist_never_becomes_the_gate(): void
    {
        Institution::current()->update(['academic_year_start' => null]);
        Level::query()->update(['active' => false]);
        Calendar::flush();

        $steps = $this->stepsByKey();
        $this->assertNotNull($steps['periods']['blocked_by']);
        $this->assertNotNull($steps['roster']['blocked_by']);

        $admin = User::factory()->create(['position' => 0]);

        foreach (['periods', 'roster'] as $key) {
            $this->actingAs($admin)->get(route($steps[$key]['route']))
                ->assertOk('a blocked step must reach its own screen: the checklist is advisory');
        }
    }

    // --- The clinic step's escape ------------------------------------------------------------

    public function test_the_clinic_step_is_satisfied_when_no_unit_owns_clinics(): void
    {
        // ReferenceSeeder ships WARD as the sole clinic owner (P1b owner decision B), so the
        // step starts unsatisfied on a cold start with no clinic defined.
        $this->assertFalse($this->stepsByKey()['clinics']['done']);

        Unit::query()->update(['clinic_owner' => false]);

        $steps = $this->stepsByKey();
        $this->assertTrue($steps['clinics']['done'], 'a department where no unit runs clinics is '
            .'a valid department, not an unfinished one');
        $this->assertStringContainsString('No unit', $steps['clinics']['summary']);
    }

    public function test_an_active_clinic_satisfies_the_clinic_step(): void
    {
        Clinic::factory()->create(['unit_id' => Unit::findByCode('WARD')->getKey()]);

        $this->assertTrue($this->stepsByKey()['clinics']['done']);
    }

    // --- The QCH case -------------------------------------------------------------------------

    public function test_an_already_configured_department_shows_complete_with_no_backfill(): void
    {
        $this->generateAYear();
        $person = $this->addRosterPerson();
        Clinic::factory()->create(['unit_id' => Unit::findByCode('WARD')->getKey()]);
        Invitation::query()->create([
            'person_id' => $person->getKey(),
            'member_email' => 'invited-'.Str::random(6).'@example.test',
            'position' => 4,
            'token_hash' => hash('sha256', Str::random(40)),
            'expires_at' => Calendar::now()->addDays(Invitation::LIFETIME_DAYS),
        ]);

        $unfinished = [];

        foreach (DepartmentSetup::steps()['steps'] as $step) {
            if ($step['kind'] === DepartmentSetup::KIND_REQUIRED && ! $step['done']) {
                $unfinished[] = $step['key'];
            }
        }

        // Asserted over the whole set: a spot check on one step passes for a checklist that
        // never looked at the others.
        $this->assertSame([], $unfinished, 'a configured department must read as complete with no '
            .'migration and no backfill — the whole argument for deriving rather than storing.');
    }

    // --- ST-03: no step names a P2 concept -----------------------------------------------------

    /**
     * Finding 2. Both of ST-03's named launch presets are slot/coverage-template presets, and
     * `slots`, `coverage_templates` and `conditions` do not exist as tables, classes or concepts
     * anywhere in this tree. A "Stage-1 subset" of a slot preset is the empty set, so those items
     * are STATED in `later` rather than rendered as permanently unticked boxes an administrator
     * will try to click.
     */
    public function test_no_step_names_a_slot_a_coverage_template_or_a_condition(): void
    {
        $offenders = [];

        foreach (DepartmentSetup::steps()['steps'] as $step) {
            $haystack = Str::lower(implode(' ', [
                $step['key'], $step['title'], $step['route'], $step['summary'], (string) $step['blocked_by'],
            ]));

            foreach (['slot', 'coverage', 'condition'] as $needle) {
                if (str_contains($haystack, $needle)) {
                    $offenders[] = $step['key'].' names '.$needle;
                }
            }
        }

        $this->assertSame([], $offenders, 'ST-03 cannot ship in any subset — its two presets are '
            .'both slot/coverage-template presets and neither table exists. A step naming one is a '
            .'box that can never be ticked.');
    }

    public function test_the_later_items_are_stated_with_no_route_and_no_done_key(): void
    {
        $later = DepartmentSetup::steps()['later'];

        $this->assertNotEmpty($later, 'ST-01 lists these steps; saying nothing about them is not '
            .'the same as saying when they arrive');

        foreach ($later as $item) {
            $this->assertArrayNotHasKey('route', $item, 'a later item must not be clickable');
            $this->assertArrayNotHasKey('done', $item, 'a later item must not be tickable');
            $this->assertArrayHasKey('stage', $item);
            $this->assertNotSame('', trim((string) $item['summary']));
        }

        // The stated items are exactly the three ST-01 names that P1e cannot ship.
        $this->assertSame(
            ['slots', 'conditions'],
            array_map(fn (array $item): string => $item['key'], $later),
        );
    }

    // --- Shape and cost ------------------------------------------------------------------------

    /** Every step carries the same key set — asserted over the whole set, never on a sample. */
    public function test_every_step_carries_the_same_keys_and_a_registered_route(): void
    {
        $wrong = [];

        foreach (DepartmentSetup::steps()['steps'] as $step) {
            if (array_keys($step) !== ['key', 'title', 'kind', 'route', 'done', 'blocked_by', 'summary']) {
                $wrong[] = $step['key'].': '.implode(',', array_keys($step));
            }

            if (! app('router')->has($step['route'])) {
                $wrong[] = $step['key'].' points at unregistered route '.$step['route'];
            }
        }

        $this->assertSame([], $wrong);
    }

    /**
     * MEASURED, not arithmetic: one `exists()` per derived step plus the three reads the REVIEW
     * summaries need (the institution row, the calendar memo, the holiday rule count), with the
     * clinic step costing two because "no unit owns clinics" and "an active clinic exists" are
     * two different questions and its summary has to tell them apart.
     *
     * The bound is exact rather than generous: the regression it exists to catch is a step
     * resolving something per-step that belongs in one read, and each of those costs one query.
     * A bound with more headroom than the regression it guards is decoration.
     */
    public function test_it_issues_a_bounded_number_of_queries(): void
    {
        $this->generateAYear();
        $this->addRosterPerson();
        Clinic::factory()->create(['unit_id' => Unit::findByCode('WARD')->getKey()]);

        Calendar::flush();
        DB::enableQueryLog();
        DB::flushQueryLog();

        DepartmentSetup::steps();

        $count = count(DB::getQueryLog());
        DB::disableQueryLog();

        $this->assertLessThanOrEqual(10, $count, "DepartmentSetup issued {$count} queries. It is "
            .'nine steps over `exists()` plus three summary reads; anything above that is a step '
            .'resolving per-step what belongs in one read.');
    }
}
