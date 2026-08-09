<?php

namespace Tests\Feature\Units;

use App\Models\Unit;
use Database\Seeders\ReferenceSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Munawib UN-02 (three independent capability flags), UN-03 (import aliases) and UN-05 (an
 * optional secondary display name). Design §6.1 claimed P0a shipped these; it did not.
 *
 * The three flags are INDEPENDENT on purpose: a subspecialty clinic that owns clinics but is
 * not a rotation and is not an on-call target is a real shape, and any two-of-three
 * combination must be storable without the third.
 */
class UnitCapabilityFlagsTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_new_unit_defaults_every_capability_flag_off(): void
    {
        $unit = Unit::create(['code' => 'RGH1', 'name' => 'Riyadh General Ward 1']);

        $this->assertFalse($unit->fresh()->training_rotation);
        $this->assertFalse($unit->fresh()->call_target);
        $this->assertFalse($unit->fresh()->clinic_owner);
    }

    /**
     * The same reasoning P0a applied to `active` (amendment 2): a half-configured department
     * must be INERT, not live. A flag that defaulted true would enrol a freshly created unit
     * into the training rotation and the call roster before anyone confirmed it belongs there.
     */
    public function test_the_three_flags_are_independent(): void
    {
        $unit = Unit::create([
            'code' => 'CLIN',
            'name' => 'Subspecialty Clinics',
            'training_rotation' => false,
            'call_target' => false,
            'clinic_owner' => true,
        ]);

        $fresh = $unit->fresh();
        $this->assertFalse($fresh->training_rotation);
        $this->assertFalse($fresh->call_target);
        $this->assertTrue($fresh->clinic_owner);
    }

    public function test_the_flags_cast_to_booleans_not_strings(): void
    {
        $unit = Unit::create([
            'code' => 'RGH2', 'name' => 'Ward 2', 'training_rotation' => 1, 'call_target' => 0,
        ]);

        $fresh = $unit->fresh();
        $this->assertIsBool($fresh->training_rotation);
        $this->assertIsBool($fresh->call_target);
        $this->assertIsBool($fresh->clinic_owner);
    }

    public function test_aliases_round_trip_as_a_list_preserving_source_spelling(): void
    {
        $unit = Unit::create([
            'code' => 'PICU2',
            'name' => 'Second PICU',
            // UN-03: source data is PRESERVED. "Paeds ICU" comes back exactly as typed.
            'aliases' => ['Paeds ICU', ' picu-2 ', 'PICU 2'],
        ]);

        $this->assertSame(['Paeds ICU', 'picu-2', 'PICU 2'], $unit->fresh()->aliases);
    }

    public function test_aliases_default_to_an_empty_list_never_null(): void
    {
        $unit = Unit::create(['code' => 'RGH3', 'name' => 'Ward 3']);

        $this->assertSame([], $unit->fresh()->aliases);
    }

    public function test_aliases_drop_blanks_and_duplicates_and_non_strings(): void
    {
        $unit = Unit::create([
            'code' => 'RGH4',
            'name' => 'Ward 4',
            'aliases' => ['Ward Four', '', '   ', 'Ward Four', 42, null, 'ward four'],
        ]);

        // De-duplication is CASE-INSENSITIVE (that is the whole point of typo tolerance), and
        // the FIRST spelling wins, because that is the one the administrator typed on purpose.
        $this->assertSame(['Ward Four'], $unit->fresh()->aliases);
    }

    public function test_a_unit_resolves_by_alias_case_and_whitespace_insensitively(): void
    {
        $unit = Unit::create(['code' => 'RGH5', 'name' => 'Ward 5', 'aliases' => ['Ward Five']]);

        $this->assertSame($unit->id, Unit::findByCodeOrAlias('  ward five ')?->id);
        $this->assertSame($unit->id, Unit::findByCodeOrAlias('RGH5')?->id);
        $this->assertSame($unit->id, Unit::findByCodeOrAlias('rgh5')?->id);
        $this->assertNull(Unit::findByCodeOrAlias('Ward Six'));
    }

    /** Code wins over another unit's alias — an exact identity beats a typo-tolerance hint. */
    public function test_code_takes_precedence_over_another_units_alias(): void
    {
        $byCode = Unit::create(['code' => 'RGH6', 'name' => 'Ward 6']);
        Unit::create(['code' => 'RGH7', 'name' => 'Ward 7', 'aliases' => ['RGH6']]);

        $this->assertSame($byCode->id, Unit::findByCodeOrAlias('RGH6')?->id);
    }

    /** UN-05: stored, and rendered NOWHERE at launch. */
    public function test_name2_is_optional_and_stored_verbatim(): void
    {
        $unit = Unit::create(['code' => 'RGH8', 'name' => 'Ward 8', 'name2' => 'العنبر ٨']);

        $this->assertSame('العنبر ٨', $unit->fresh()->name2);
        $this->assertNull(Unit::create(['code' => 'RGH9', 'name' => 'Ward 9'])->fresh()->name2);
    }

    /**
     * UN-05 is "stored for future translations; unused at launch". Leaking it into the client
     * contract now would give a future consumer a prop with no rendering rules.
     */
    public function test_name2_does_not_reach_the_client_contract(): void
    {
        $unit = Unit::create(['code' => 'RGHA', 'name' => 'Ward A', 'name2' => 'Secondary']);

        $this->assertArrayNotHasKey('name2', $unit->profile()->toArray());
    }

    public function test_the_four_seeded_units_are_rotations_and_call_targets(): void
    {
        $this->seed(ReferenceSeeder::class);

        foreach (['PICU', 'NICU', 'SCBU', 'WARD'] as $code) {
            $unit = Unit::findByCode($code);

            $this->assertTrue($unit->training_rotation, $code);
            $this->assertTrue($unit->call_target, $code);
            $this->assertSame([], $unit->aliases, $code);
        }
    }

    /**
     * Owner decision B (2026-08-09, P1b OWNER DECISIONS): WARD is the only clinic owner. Seeded
     * even though no clinic concept exists until P1e — "affects nothing before P1e, but settles
     * CL-01's first screen".
     */
    public function test_ward_alone_is_seeded_as_a_clinic_owner(): void
    {
        $this->seed(ReferenceSeeder::class);

        $this->assertTrue(Unit::findByCode('WARD')->clinic_owner);
        $this->assertFalse(Unit::findByCode('PICU')->clinic_owner);
        $this->assertFalse(Unit::findByCode('NICU')->clinic_owner);
        $this->assertFalse(Unit::findByCode('SCBU')->clinic_owner);
    }

    /** A re-seed refreshes `name` only — an administrator's flags are theirs (P0a precedent). */
    public function test_reseeding_preserves_administrator_flag_changes(): void
    {
        $this->seed(ReferenceSeeder::class);
        Unit::findByCode('WARD')->update(['call_target' => false, 'clinic_owner' => true]);

        $this->seed(ReferenceSeeder::class);

        $ward = Unit::findByCode('WARD');
        $this->assertFalse($ward->call_target);
        $this->assertTrue($ward->clinic_owner);
    }
}
