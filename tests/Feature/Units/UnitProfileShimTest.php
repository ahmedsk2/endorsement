<?php

namespace Tests\Feature\Units;

use App\Models\Unit;
use App\Support\UnitProfile;
use Database\Seeders\ReferenceSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use InvalidArgumentException;
use Tests\TestCase;

/**
 * `UnitProfile::codes()` and `::for()` are TEMPORARY, deprecated DB-backed shims (design
 * §6.1) kept only so the eleven existing callers in EndorsementController,
 * SendHandoverReminders and ProfileController keep working unmigrated. Task 5 of the P0a plan
 * deletes both statics once those callers move to `Unit::codes()` / `$unit->profile()` — and
 * deletes this whole file with them, which is why the temporary scope is in the filename.
 *
 * Unlike `tests/Unit/UnitProfileTest.php` (database-free, `fromUnit()`/`toArray()` only),
 * these shims resolve against real rows, so this lives under Feature with RefreshDatabase.
 */
class UnitProfileShimTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(ReferenceSeeder::class);
    }

    public function test_for_shim_resolves_a_seeded_unit_matching_its_profile_method(): void
    {
        $unit = Unit::where('code', 'PICU')->firstOrFail();

        $viaShim = UnitProfile::for('PICU');
        $viaModel = $unit->profile();

        $this->assertEquals($viaModel, $viaShim);
        $this->assertSame('PICU', $viaShim->code);
        $this->assertSame('channel-bar-picu', $viaShim->barClass);
    }

    public function test_for_shim_is_case_insensitive(): void
    {
        $this->assertSame('WARD', UnitProfile::for('ward')->code);
    }

    public function test_for_shim_throws_for_an_unknown_code(): void
    {
        $this->expectException(InvalidArgumentException::class);

        UnitProfile::for('ICU');
    }

    public function test_for_shim_throws_for_a_deactivated_unit(): void
    {
        Unit::where('code', 'SCBU')->update(['active' => false]);

        $this->expectException(InvalidArgumentException::class);

        UnitProfile::for('SCBU');
    }

    public function test_codes_delegates_to_unit_codes(): void
    {
        $this->assertSame(['PICU', 'NICU', 'SCBU', 'WARD'], UnitProfile::codes());
        $this->assertSame(Unit::codes(), UnitProfile::codes());
    }
}
