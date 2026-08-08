<?php

namespace Tests\Feature\Units;

use App\Models\Unit;
use Database\Seeders\ReferenceSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The units table is now the SINGLE source of per-unit variation (design §6.1). These tests
 * pin the four seeded paediatric profiles, so the move off the hardcoded UnitProfile registry
 * is provably behaviour-preserving.
 */
class UnitConfigurationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(ReferenceSeeder::class);
    }

    public function test_picu_shows_no_extra_identity_columns(): void
    {
        $picu = Unit::where('code', 'PICU')->firstOrFail();

        $this->assertSame([], $picu->extra_row_fields);
        $this->assertSame('Bed', $picu->bed_label);
        $this->assertTrue($picu->consultant_pair);
        $this->assertSame('Consultant covering', $picu->consultant_by_label);
        $this->assertSame('channel-bar-picu', $picu->bar_class);
        $this->assertSame('Plan Of Care', $picu->print_plan_label);
        $this->assertSame('New events', $picu->print_narrative_label);
        $this->assertTrue($picu->active);
    }

    public function test_nicu_and_scbu_add_dob(): void
    {
        foreach (['NICU', 'SCBU'] as $code) {
            $unit = Unit::where('code', $code)->firstOrFail();

            $this->assertSame(['dob'], $unit->extra_row_fields, $code);
            $this->assertSame('Bed', $unit->bed_label, $code);
            $this->assertTrue($unit->consultant_pair, $code);
            $this->assertSame('Plan Of Care', $unit->print_plan_label, $code);
            $this->assertSame('To be followed', $unit->print_narrative_label, $code);
        }

        $this->assertSame('channel-bar-nicu', Unit::where('code', 'NICU')->value('bar_class'));
        $this->assertSame('channel-bar-scbu', Unit::where('code', 'SCBU')->value('bar_class'));
    }

    /** Ruling 5 — WARD has ONE consultant field, labelled "Consultant Oncall". */
    public function test_ward_carries_its_own_shape(): void
    {
        $ward = Unit::where('code', 'WARD')->firstOrFail();

        $this->assertSame(['age', 'ward_unit'], $ward->extra_row_fields);
        $this->assertSame('Room', $ward->bed_label);
        $this->assertFalse($ward->consultant_pair);
        $this->assertSame('Consultant Oncall', $ward->consultant_by_label);
        $this->assertSame('channel-bar-ward', $ward->bar_class);
        $this->assertSame('Management', $ward->print_plan_label);
        $this->assertSame('To be followed', $ward->print_narrative_label);
    }

    public function test_display_order_matches_the_historical_code_order(): void
    {
        $this->assertSame(
            ['PICU', 'NICU', 'SCBU', 'WARD'],
            Unit::orderBy('display_order')->pluck('code')->all()
        );
    }

    /**
     * A re-seed refreshes `name` only. The profile columns are seeded once and then belong to
     * the department — an admin's configuration must never be silently reverted.
     */
    public function test_reseeding_preserves_admin_configuration(): void
    {
        Unit::where('code', 'PICU')->update(['bed_label' => 'Cot', 'print_plan_label' => 'Plan']);

        $this->seed(ReferenceSeeder::class);

        $picu = Unit::where('code', 'PICU')->firstOrFail();
        $this->assertSame('Cot', $picu->bed_label);
        $this->assertSame('Plan', $picu->print_plan_label);
        $this->assertSame('Pediatric Intensive Care Unit', $picu->name);
    }

    public function test_codes_returns_active_units_in_display_order(): void
    {
        $this->assertSame(['PICU', 'NICU', 'SCBU', 'WARD'], Unit::codes());
    }

    public function test_codes_omits_deactivated_units(): void
    {
        Unit::where('code', 'SCBU')->update(['active' => false]);

        $this->assertSame(['PICU', 'NICU', 'WARD'], Unit::codes());
    }

    public function test_ordered_sorts_by_display_order_not_insertion_order(): void
    {
        Unit::where('code', 'WARD')->update(['display_order' => 0]);

        $this->assertSame(['WARD', 'PICU', 'NICU', 'SCBU'], Unit::query()->ordered()->pluck('code')->all());
    }

    public function test_ordered_breaks_ties_by_id(): void
    {
        $this->assertStringContainsString(
            'order by "display_order" asc, "id" asc',
            Unit::query()->ordered()->toSql()
        );
    }
}
