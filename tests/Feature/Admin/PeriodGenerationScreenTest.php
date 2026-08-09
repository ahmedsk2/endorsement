<?php

namespace Tests\Feature\Admin;

use App\Models\AuditLog;
use App\Models\Institution;
use App\Models\MasterRotaAssignment;
use App\Models\Period;
use App\Models\Person;
use App\Models\Unit;
use App\Models\User;
use App\Support\Calendar;
use App\Support\PeriodGenerator;
use Carbon\CarbonImmutable;
use Database\Seeders\AccessControlSeeder;
use Database\Seeders\ReferenceSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use RuntimeException;
use Tests\TestCase;

/**
 * Finding 4: `PeriodGenerator` has ZERO production callers before this screen, so `periods` can
 * never be populated by the application — and P1d's rota grid has no columns without it. This
 * screen ships preview AND commit AND the delete path Decision D's hard-lock names as its
 * unlock, which is more than the P1 plan's own one-line item ("the period-run preview and its
 * gap/overlap warning") asked for.
 */
class PeriodGenerationScreenTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(AccessControlSeeder::class);
        $this->seed(ReferenceSeeder::class);
        $this->admin = User::factory()->create(['position' => 0]);
    }

    private function configureWeekBlocks(?string $academicYearStart = '2026-07-01'): void
    {
        Institution::current()->update([
            'period_type' => Institution::PERIOD_WEEK_BLOCKS,
            'academic_year_start' => $academicYearStart,
            'block_weeks' => [4, 4, 4, 4, 4, 4, 4, 4, 4, 4, 4, 4, 5],
        ]);
        Calendar::flush();
    }

    private function configureMonths(string $academicYearStart = '2026-07-01'): void
    {
        Institution::current()->update([
            'period_type' => Institution::PERIOD_MONTHS,
            'academic_year_start' => $academicYearStart,
        ]);
        Calendar::flush();
    }

    // --- Preview -----------------------------------------------------------------------------

    /**
     * The exact numbers `PeriodGenerationTest::test_week_blocks_final_block_absorbs_the_remainder_before_next_years_start`
     * already pins — asserted against the generator directly here (not copied from the plan),
     * then checked against what the screen returns.
     */
    public function test_preview_matches_the_generator_for_a_full_week_block_year(): void
    {
        $this->configureWeekBlocks();
        $expected = PeriodGenerator::weekBlocks(
            CarbonImmutable::parse('2026-07-01'),
            [4, 4, 4, 4, 4, 4, 4, 4, 4, 4, 4, 4, 5],
            CarbonImmutable::parse('2027-07-01'),
        );
        $this->assertCount(13, $expected);
        $this->assertSame('2027-06-02', $expected[12]['starts_on']);
        $this->assertSame('2027-06-30', $expected[12]['ends_on']);

        $this->actingAs($this->admin)
            ->get('/admin/structure/periods?next_year_start=2027-07-01')
            ->assertInertia(fn (Assert $page) => $page
                ->component('Admin/Periods')
                ->has('preview.periods', 13)
                ->where('preview.periods.12.starts_on', $expected[12]['starts_on'])
                ->where('preview.periods.12.ends_on', $expected[12]['ends_on'])
                ->where('preview.total_days', 365)
            );
    }

    /** The preview convenience: without a next-year start, block 13 falls back to its nominal length. */
    public function test_preview_falls_back_to_the_nominal_block_thirteen_without_a_next_year_start(): void
    {
        $this->configureWeekBlocks();

        $this->actingAs($this->admin)
            ->get('/admin/structure/periods')
            ->assertInertia(fn (Assert $page) => $page
                ->where('preview.periods.12.starts_on', '2027-06-02')
                ->where('preview.periods.12.ends_on', '2027-07-06')
                ->where('preview.used_fallback_block', true)
            );
    }

    public function test_preview_under_months_produces_twelve_calendar_months(): void
    {
        $this->configureMonths();

        $this->actingAs($this->admin)
            ->get('/admin/structure/periods')
            ->assertInertia(fn (Assert $page) => $page
                ->has('preview.periods', 12)
                ->where('preview.periods.0.label', 'July 2026')
                ->where('preview.periods.11.label', 'June 2027')
                ->where('preview.total_days', 365)
            );
    }

    public function test_previewing_writes_nothing(): void
    {
        $this->configureWeekBlocks();
        $before = Period::count();

        $this->actingAs($this->admin)->get('/admin/structure/periods?next_year_start=2027-07-01');

        $this->assertSame($before, Period::count());
    }

    /** A gap against an adjacent persisted year is surfaced as a WARNING; the button stays enabled. */
    public function test_preview_surfaces_a_gap_against_a_previous_year_as_a_warning(): void
    {
        $this->configureWeekBlocks();
        Period::create([
            'institution_id' => null, 'academic_year' => '2025-2026', 'kind' => Period::WEEK_BLOCK,
            'position' => 13, 'label' => 'Block 13', 'starts_on' => '2026-06-01', 'ends_on' => '2026-06-20',
        ]);

        $this->actingAs($this->admin)
            ->get('/admin/structure/periods')
            ->assertInertia(fn (Assert $page) => $page
                ->has('preview.warnings', 1)
                ->where('generate_disabled', false)
            );
    }

    // --- Commit ------------------------------------------------------------------------------

    public function test_generating_persists_the_full_week_block_year(): void
    {
        $this->configureWeekBlocks();

        $this->actingAs($this->admin)
            ->post('/admin/structure/periods', ['next_year_start' => '2027-07-01'])
            ->assertRedirect()
            ->assertSessionHasNoErrors();

        $this->assertSame(13, Period::where('academic_year', '2026-2027')->count());
        $last = Period::where('academic_year', '2026-2027')->where('position', 13)->firstOrFail();
        $this->assertSame('2027-06-02', $last->starts_on->format('Y-m-d'));
        $this->assertSame('2027-06-30', $last->ends_on->format('Y-m-d'));
        $this->assertSame($this->admin->institution_id, $last->institution_id);
    }

    public function test_the_derived_academic_year_label_is_two_years_for_a_july_start(): void
    {
        $this->configureWeekBlocks();

        $this->actingAs($this->admin)->post('/admin/structure/periods', ['next_year_start' => '2027-07-01']);

        $this->assertSame(0, Period::where('academic_year', '2026')->count());
        $this->assertSame(13, Period::where('academic_year', '2026-2027')->count());
    }

    public function test_the_derived_academic_year_label_is_one_year_for_a_january_start_under_months(): void
    {
        $this->configureMonths('2026-01-01');

        $this->actingAs($this->admin)->post('/admin/structure/periods');

        $this->assertSame(12, Period::where('academic_year', '2026')->count());
    }

    public function test_generating_twice_for_the_same_year_is_refused_and_leaves_rows_untouched(): void
    {
        $this->configureWeekBlocks();
        $this->actingAs($this->admin)->post('/admin/structure/periods', ['next_year_start' => '2027-07-01']);
        $this->assertSame(13, Period::count());

        $response = $this->actingAs($this->admin)
            ->post('/admin/structure/periods', ['next_year_start' => '2027-07-01']);

        $response->assertSessionHasErrors();
        $this->assertStringContainsString('2026-2027', collect(session('errors')->all())->implode(' '));
        $this->assertStringContainsString('delete', strtolower(collect(session('errors')->all())->implode(' ')));
        $this->assertSame(13, Period::count());
    }

    /**
     * Finding 14: `Period::booted()`'s overlap guard throws a RuntimeException, not a
     * ValidationException. The commit path pre-checks the ADJACENT persisted year and converts
     * a real day-collision into a 422 before any Period::create() runs.
     */
    public function test_a_real_overlap_against_the_previous_years_persisted_periods_is_a_422_not_a_500(): void
    {
        $this->configureWeekBlocks();
        // The previous year's last period runs PAST this year's intended start — a genuine
        // day-collision, not merely a gap.
        Period::create([
            'institution_id' => null, 'academic_year' => '2025-2026', 'kind' => Period::WEEK_BLOCK,
            'position' => 13, 'label' => 'Block 13 (2025-2026)', 'starts_on' => '2026-06-01', 'ends_on' => '2026-07-10',
        ]);

        $response = $this->actingAs($this->admin)
            ->withHeaders(['X-Inertia' => 'true', 'Accept' => 'application/json'])
            ->post('/admin/structure/periods', ['next_year_start' => '2027-07-01']);

        $response->assertStatus(422);
        $body = (string) json_encode($response->json());
        $this->assertStringContainsString('Block 13 (2025-2026)', $body);
        $this->assertStringContainsString('2026-2027', $body);
        $this->assertSame(0, Period::where('academic_year', '2026-2027')->count());
    }

    /** A GAP (not an overlap) against a neighbour does not block the commit. */
    public function test_a_gap_against_the_previous_year_does_not_block_generation(): void
    {
        $this->configureWeekBlocks();
        Period::create([
            'institution_id' => null, 'academic_year' => '2025-2026', 'kind' => Period::WEEK_BLOCK,
            'position' => 13, 'label' => 'Block 13 (2025-2026)', 'starts_on' => '2026-06-01', 'ends_on' => '2026-06-20',
        ]);

        $this->actingAs($this->admin)
            ->post('/admin/structure/periods', ['next_year_start' => '2027-07-01'])
            ->assertSessionHasNoErrors();

        $this->assertSame(13, Period::where('academic_year', '2026-2027')->count());
    }

    /** The whole commit is one transaction — a failure on the LAST row leaves zero rows. */
    public function test_a_failure_partway_through_leaves_zero_rows_persisted(): void
    {
        $this->configureWeekBlocks();

        Period::creating(function (Period $period): void {
            if ($period->position === 13) {
                throw new RuntimeException('Simulated failure on the last period, for the test only.');
            }
        });

        try {
            $this->actingAs($this->admin)
                ->post('/admin/structure/periods', ['next_year_start' => '2027-07-01']);
        } finally {
            Period::flushEventListeners();
        }

        $this->assertSame(0, Period::count());
    }

    public function test_generating_writes_one_summary_audit_row_naming_year_kind_and_count(): void
    {
        $this->configureWeekBlocks();

        $this->actingAs($this->admin)->post('/admin/structure/periods', ['next_year_start' => '2027-07-01']);

        $row = AuditLog::where('action', 'periods_generate')->latest('id')->first();
        $this->assertNotNull($row);
        $this->assertStringContainsString('year=2026-2027', $row->detail);
        $this->assertStringContainsString('kind=week_block', $row->detail);
        $this->assertStringContainsString('count=13', $row->detail);
    }

    // --- Delete ------------------------------------------------------------------------------

    private function generateOneYear(): void
    {
        $this->configureWeekBlocks();
        $this->actingAs($this->admin)->post('/admin/structure/periods', ['next_year_start' => '2027-07-01']);
        $this->assertSame(13, Period::count());
    }

    public function test_deleting_requires_typing_the_academic_year_not_a_bare_confirm(): void
    {
        $this->generateOneYear();

        $this->actingAs($this->admin)
            ->delete('/admin/structure/periods/2026-2027', ['confirm_academic_year' => 'yes'])
            ->assertSessionHasErrors('confirm_academic_year');

        $this->assertSame(13, Period::count());
    }

    public function test_typing_the_exact_year_deletes_it_and_is_audited(): void
    {
        $this->generateOneYear();

        $this->actingAs($this->admin)
            ->delete('/admin/structure/periods/2026-2027', ['confirm_academic_year' => '2026-2027'])
            ->assertRedirect()
            ->assertSessionHasNoErrors();

        $this->assertSame(0, Period::where('academic_year', '2026-2027')->count());

        $row = AuditLog::where('action', 'periods_delete')->latest('id')->first();
        $this->assertNotNull($row);
        $this->assertStringContainsString('year=2026-2027', $row->detail);
        $this->assertStringContainsString('count=13', $row->detail);
    }

    /**
     * P1d Task 4 closes the hook this test used to pin as open: `master_rota_assignments` now
     * exists, so a year with a rota assignment against it is refused outright — never a partial
     * delete of the periods an assignment does NOT reference.
     */
    public function test_deleting_a_year_is_refused_while_a_rota_assignment_references_it(): void
    {
        $this->generateOneYear();

        $period = Period::query()->where('academic_year', '2026-2027')->orderBy('starts_on')->first();

        MasterRotaAssignment::create([
            'person_id' => Person::factory()->create()->getKey(),
            'period_id' => $period->getKey(),
            'unit_id' => Unit::create(['code' => 'RTA', 'name' => 'Rota Test A', 'active' => true])->getKey(),
            'starts_on' => $period->starts_on->format('Y-m-d'),
            'ends_on' => $period->ends_on->format('Y-m-d'),
        ]);

        $this->actingAs($this->admin)
            ->delete('/admin/structure/periods/2026-2027', ['confirm_academic_year' => '2026-2027'])
            ->assertSessionHasErrors('confirm_academic_year');

        // Not one period gone — the refusal is total, never partial.
        $this->assertSame(13, Period::query()->where('academic_year', '2026-2027')->count());
    }

    public function test_deleting_a_year_still_succeeds_once_nothing_references_it(): void
    {
        $this->generateOneYear();

        $this->actingAs($this->admin)
            ->delete('/admin/structure/periods/2026-2027', ['confirm_academic_year' => '2026-2027'])
            ->assertSessionHasNoErrors();

        $this->assertSame(0, Period::query()->where('academic_year', '2026-2027')->count());
    }

    /** Decision D's unlock, proven end to end: delete the year, and the calendar screen unlocks. */
    public function test_deleting_the_only_year_unlocks_the_calendar_settings_screen(): void
    {
        $this->generateOneYear();

        $this->actingAs($this->admin)->get('/admin/structure/calendar')
            ->assertInertia(fn (Assert $page) => $page->where('locked', true));

        $this->actingAs($this->admin)
            ->delete('/admin/structure/periods/2026-2027', ['confirm_academic_year' => '2026-2027']);

        $this->actingAs($this->admin)->get('/admin/structure/calendar')
            ->assertInertia(fn (Assert $page) => $page->where('locked', false));
    }

    // --- Access --------------------------------------------------------------------------------

    public function test_a_resident_cannot_reach_the_screen(): void
    {
        $resident = User::factory()->create(['position' => 4]);

        $this->actingAs($resident)->get('/admin/structure/periods')->assertForbidden();
        $this->actingAs($resident)
            ->post('/admin/structure/periods', ['next_year_start' => '2027-07-01'])
            ->assertForbidden();
    }

    public function test_a_guest_is_redirected_to_login(): void
    {
        $this->get('/admin/structure/periods')->assertRedirect('/login');
    }
}
