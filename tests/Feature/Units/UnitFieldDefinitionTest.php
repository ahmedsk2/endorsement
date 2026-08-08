<?php

namespace Tests\Feature\Units;

use App\Models\Unit;
use App\Models\UnitFieldDefinition;
use Database\Seeders\ReferenceSeeder;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * `unit_field_definitions` is the data half of P0b's bounded custom fields (design §6.2) — a
 * department describes its own handover-sheet fields as rows rather than a code change. These
 * tests pin the table's shape before anything else reads or writes it: ordering, the
 * deliberate opt-in/opt-out asymmetry with `units.active` (a definition someone bothered to
 * create is meant to be used — and it is inert anyway until a unit renders it), and the
 * uniqueness that keeps a stored `extra_fields` key addressable by exactly one definition.
 */
class UnitFieldDefinitionTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(ReferenceSeeder::class);
    }

    public function test_a_definition_attaches_to_a_unit_and_reads_back(): void
    {
        $unit = Unit::where('code', 'PICU')->firstOrFail();

        $definition = UnitFieldDefinition::create([
            'unit_id' => $unit->id,
            'key' => 'weight',
            'label' => 'Weight (kg)',
            'type' => 'text',
        ]);

        $this->assertSame($unit->id, $definition->unit_id);
        $this->assertSame('weight', $definition->key);
        $this->assertSame('Weight (kg)', $definition->label);

        $fresh = Unit::where('code', 'PICU')->firstOrFail();
        $this->assertSame(['weight'], $fresh->fieldDefinitions->pluck('key')->all());
    }

    public function test_field_definitions_are_ordered_by_display_order_then_id(): void
    {
        $unit = Unit::where('code', 'PICU')->firstOrFail();

        $second = UnitFieldDefinition::create([
            'unit_id' => $unit->id, 'key' => 'weight', 'label' => 'Weight', 'display_order' => 2,
        ]);
        $first = UnitFieldDefinition::create([
            'unit_id' => $unit->id, 'key' => 'allergy', 'label' => 'Allergy', 'display_order' => 1,
        ]);
        // Same display_order as an earlier row: must break the tie by id, not re-sort by key.
        $thirdTiedOnOrder = UnitFieldDefinition::create([
            'unit_id' => $unit->id, 'key' => 'oxygen', 'label' => 'Oxygen', 'display_order' => 2,
        ]);

        $this->assertSame(
            [$first->id, $second->id, $thirdTiedOnOrder->id],
            $unit->fieldDefinitions()->pluck('id')->all()
        );
    }

    public function test_active_defaults_to_true(): void
    {
        $unit = Unit::where('code', 'PICU')->firstOrFail();

        $definition = UnitFieldDefinition::create([
            'unit_id' => $unit->id,
            'key' => 'weight',
            'label' => 'Weight (kg)',
        ]);

        // The in-memory instance only reflects columns explicitly passed to create(); the
        // DB-level default is only visible on a re-fetch, which is what actually matters here.
        $this->assertTrue($definition->fresh()->active);
    }

    public function test_the_unit_and_key_pair_is_unique(): void
    {
        $unit = Unit::where('code', 'PICU')->firstOrFail();

        UnitFieldDefinition::create(['unit_id' => $unit->id, 'key' => 'weight', 'label' => 'Weight (kg)']);

        $this->expectException(QueryException::class);

        UnitFieldDefinition::create(['unit_id' => $unit->id, 'key' => 'weight', 'label' => 'Weight, again']);
    }

    public function test_the_same_key_is_allowed_on_a_different_unit(): void
    {
        $picu = Unit::where('code', 'PICU')->firstOrFail();
        $nicu = Unit::where('code', 'NICU')->firstOrFail();

        UnitFieldDefinition::create(['unit_id' => $picu->id, 'key' => 'weight', 'label' => 'Weight (kg)']);
        UnitFieldDefinition::create(['unit_id' => $nicu->id, 'key' => 'weight', 'label' => 'Weight (kg)']);

        $this->assertSame(2, UnitFieldDefinition::where('key', 'weight')->count());
    }

    public function test_field_definitions_excludes_inactive_definitions(): void
    {
        $unit = Unit::where('code', 'PICU')->firstOrFail();

        UnitFieldDefinition::create([
            'unit_id' => $unit->id, 'key' => 'weight', 'label' => 'Weight (kg)', 'active' => true,
        ]);
        UnitFieldDefinition::create([
            'unit_id' => $unit->id, 'key' => 'retired', 'label' => 'Retired Field', 'active' => false,
        ]);

        $fresh = Unit::where('code', 'PICU')->firstOrFail();
        $this->assertSame(['weight'], $fresh->fieldDefinitions->pluck('key')->all());

        // The row itself survives untouched — retiring a definition hides it, never deletes it.
        $this->assertSame(2, UnitFieldDefinition::where('unit_id', $unit->id)->count());
    }

    public function test_the_four_seeded_paediatric_units_have_zero_field_definitions(): void
    {
        foreach (['PICU', 'NICU', 'SCBU', 'WARD'] as $code) {
            $unit = Unit::where('code', $code)->firstOrFail();
            $this->assertSame([], $unit->fieldDefinitions->all(), $code);
        }
    }
}
