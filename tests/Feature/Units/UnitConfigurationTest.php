<?php

namespace Tests\Feature\Units;

use App\Models\Unit;
use App\Models\User;
use Database\Seeders\AccessControlSeeder;
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

    /**
     * Unit::codes() plucks stored codes VERBATIM into a whereIn, while lookups elsewhere
     * compare case-sensitively after uppercasing the input. A unit stored lowercase would be
     * selected by the chooser query and then not findable — normalizing on write is what
     * keeps storage and lookup from disagreeing.
     */
    public function test_creating_a_unit_with_a_lowercase_code_stores_it_uppercase(): void
    {
        $unit = Unit::create([
            'code' => 'nur',
            'name' => 'Nursery',
            'display_order' => 5,
            'active' => true,
        ]);

        $this->assertSame('NUR', $unit->code);
        $this->assertSame('NUR', $unit->fresh()->code);
        $this->assertContains('NUR', Unit::codes());
    }

    public function test_creating_a_unit_with_surrounding_whitespace_stores_it_trimmed(): void
    {
        $unit = Unit::create([
            'code' => '  ICU  ',
            'name' => 'Intensive Care Unit',
            'display_order' => 6,
            'active' => true,
        ]);

        $this->assertSame('ICU', $unit->code);
        $this->assertSame('ICU', $unit->fresh()->code);
    }

    /**
     * Without normalization the row stores `nur`; `resolveUnit()` uppercases the URL segment
     * to `NUR`; SQLite compares case-sensitively; the sheet 404s. The chooser assertion is
     * kept alongside it since it still exercises the chooser's own listing query, but the
     * route assertion below is the one that must carry the weight — it is the assertion that
     * actually fails (404 instead of 200) when the `code` mutator is neutered.
     */
    public function test_a_lowercase_active_unit_does_not_break_the_chooser(): void
    {
        $this->seed(AccessControlSeeder::class);

        Unit::create([
            'code' => 'nur',
            'name' => 'Nursery',
            'display_order' => 5,
            'active' => true,
        ]);

        $admin = User::factory()->create(['position' => 0]);

        $this->actingAs($admin)->get('/endorsement')->assertOk();
        $this->actingAs($admin)->get('/endorsement/nur/2026-07-10')->assertOk();
    }

    /**
     * `Unit::findByCode()` is the required lookup path because the `code` mutator only
     * normalizes what gets STORED on write, not a query's WHERE value: a bare
     * `where('code', 'nur')` would miss a row stored as `NUR`. Confirms lowercase, padded,
     * and canonical input all resolve to the same row, and an unknown code resolves to null.
     */
    public function test_find_by_code_normalizes_the_lookup_key(): void
    {
        $picu = Unit::where('code', 'PICU')->firstOrFail();

        $this->assertSame($picu->id, Unit::findByCode('picu')?->id);
        $this->assertSame($picu->id, Unit::findByCode('  PICU  ')?->id);
        $this->assertSame($picu->id, Unit::findByCode('PICU')?->id);
        $this->assertNull(Unit::findByCode('nope'));
    }

    /**
     * `resolveUnit()` no longer aborts explicitly — it relies on `firstOrFail()` against an
     * active-scoped query, so a RETIRED unit reaches the framework as a ModelNotFoundException
     * rather than an abort(404). That only reads as "gone" to a clinician if the real handler
     * converts it; if it ever surfaced as a 500 the retired unit would look like an outage
     * instead of a decommissioning. Asserted through the full HTTP stack, on every verb that
     * takes a {unit} code — reads and writes alike — and on the chooser that must stop
     * offering it.
     */
    public function test_a_deactivated_unit_code_is_404_on_every_route_that_takes_one(): void
    {
        $this->seed(AccessControlSeeder::class);

        Unit::where('code', 'SCBU')->firstOrFail()->update(['active' => false]);

        $editor = User::factory()->create(['position' => 2]);

        foreach (['SCBU', 'scbu'] as $code) {
            $this->actingAs($editor)->get('/endorsement/'.$code)->assertNotFound();
            $this->actingAs($editor)->get('/endorsement/'.$code.'/2026-07-10')->assertNotFound();
            $this->actingAs($editor)->post('/endorsement/'.$code.'/new-day', [])->assertNotFound();
            $this->actingAs($editor)->post('/endorsement/'.$code.'/2026-07-10/rows', [])->assertNotFound();
        }

        $this->actingAs($editor)->get('/endorsement')->assertOk();
        $this->assertNotContains('SCBU', Unit::codes());
    }
}
