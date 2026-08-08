<?php

namespace Tests\Feature;

use App\Models\Unit;
use Database\Seeders\ReferenceSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ReferenceSeederTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_seeds_the_five_positions_with_nurse_retired(): void
    {
        $this->seed(ReferenceSeeder::class);

        foreach ([0 => 'Administrator', 2 => 'Charge Nurse', 3 => 'Consultant', 4 => 'Resident', 5 => 'Chief Resident'] as $id => $name) {
            $this->assertDatabaseHas('positions', ['id' => $id, 'name' => $name]);
        }

        // Position 1 (Nurse) is retired - the catalog row must not exist.
        $this->assertDatabaseMissing('positions', ['id' => 1]);
    }

    /**
     * Four first-class units — every one of them must exist for the routing
     * surface (/endorsement/{code}) to resolve.
     */
    public function test_it_seeds_all_four_units(): void
    {
        $this->seed(ReferenceSeeder::class);

        $this->assertSame(4, Unit::count());
        foreach (['PICU', 'NICU', 'SCBU', 'WARD'] as $code) {
            $this->assertDatabaseHas('units', ['code' => $code]);
        }
    }

    public function test_it_is_idempotent(): void
    {
        $this->seed(ReferenceSeeder::class);
        $this->seed(ReferenceSeeder::class);

        $this->assertSame(4, Unit::count());
        $this->assertDatabaseHas('institutions', ['code' => 'QCH']);
    }

    /**
     * The seeder must never rename or destroy a unit row somebody created by hand —
     * updateOrCreate keys on `code`, so unrelated rows are untouched.
     */
    public function test_it_retains_pre_existing_extra_units(): void
    {
        $extra = Unit::create(['code' => 'XX', 'name' => 'Somewhere Else']);

        $this->seed(ReferenceSeeder::class);

        $this->assertDatabaseHas('units', ['id' => $extra->id, 'code' => 'XX', 'name' => 'Somewhere Else']);
        $this->assertSame(5, Unit::count());
    }

    /**
     * The institution is configuration (Task 3), not a hardcoded literal — a second customer
     * must not be seeded as "Qatif Central Hospital".
     */
    public function test_it_seeds_a_configured_institution_and_not_qch(): void
    {
        config([
            'endorsement.institution.code' => 'RGH',
            'endorsement.institution.name' => 'Riyadh General Hospital',
        ]);

        $this->seed(ReferenceSeeder::class);

        $this->assertDatabaseHas('institutions', ['code' => 'RGH', 'name' => 'Riyadh General Hospital']);
        $this->assertDatabaseMissing('institutions', ['code' => 'QCH']);
    }

    /**
     * With nothing configured, the live deployment's behaviour is unchanged: QCH.
     */
    public function test_with_nothing_configured_it_still_seeds_qch(): void
    {
        $this->seed(ReferenceSeeder::class);

        $this->assertDatabaseHas('institutions', ['code' => 'QCH', 'name' => 'Qatif Central Hospital']);
    }

    /**
     * The rename test (finding 8): `name` is written on CREATE only. A re-seed — a mandatory
     * go-live step (`docs/RUNBOOK-DEPLOY.md`) — must never revert a customer's rename.
     */
    public function test_a_reseed_does_not_revert_a_renamed_institution(): void
    {
        $this->seed(ReferenceSeeder::class);

        \App\Models\Institution::where('code', 'QCH')->update(['name' => "Qatif Children's Hospital"]);

        $this->seed(ReferenceSeeder::class);

        $this->assertDatabaseHas('institutions', ['code' => 'QCH', 'name' => "Qatif Children's Hospital"]);
    }
}
