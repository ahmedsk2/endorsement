<?php

namespace Tests\Feature;

use App\Models\Institution;
use App\Models\Level;
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

    /**
     * Owner decision 1 (P1 plan, binding): the level ladder is `R1, R2, R3, R4, EXT`, seeded
     * rather than left as an undecided empty table. `display_order` is explicit and gapped by
     * ten (10/20/30/40/90) — NOT the migration's default of 1000, which would leave all five
     * levels tied and the ordering effectively undefined.
     *
     * Owner Decision A (2026-08-09) means none of the five carries a `terminal` flag: that
     * column was never built (see LevelLadderTest), so there is nothing to assert here beyond
     * `external`.
     */
    public function test_it_seeds_the_five_levels_in_display_order(): void
    {
        $this->seed(ReferenceSeeder::class);

        $this->assertSame(5, Level::count());
        $this->assertSame(
            ['R1', 'R2', 'R3', 'R4', 'EXT'],
            Level::query()->ordered()->pluck('code')->all()
        );
        $this->assertSame(
            [10, 20, 30, 40, 90],
            Level::query()->ordered()->pluck('display_order')->map(intval(...))->all()
        );
    }

    /** `EXT` sits outside the training ladder (Munawib PE-03) and is flagged accordingly. */
    public function test_ext_alone_is_seeded_external(): void
    {
        $this->seed(ReferenceSeeder::class);

        foreach (['R1', 'R2', 'R3', 'R4'] as $code) {
            $this->assertFalse(Level::where('code', $code)->value('external'), $code);
        }

        $this->assertTrue((bool) Level::where('code', 'EXT')->value('external'));
    }

    /**
     * The P0d backfill deliberately skipped `levels` (finding 5 — it had no rows to backfill at
     * the time), so nothing else will ever set `institution_id` on a seeded level except this
     * seeder, at CREATE time.
     */
    public function test_every_seeded_level_carries_the_institutions_id(): void
    {
        $this->seed(ReferenceSeeder::class);

        $institutionId = Institution::where('code', 'QCH')->value('id');

        foreach (['R1', 'R2', 'R3', 'R4', 'EXT'] as $code) {
            $this->assertSame($institutionId, Level::where('code', $code)->value('institution_id'), $code);
        }
    }

    /**
     * LV-01: names are cosmetic and administrator-owned after the seed. `db:seed --force` runs
     * on every deploy, so a rename must survive it — the same `firstOrNew` +
     * `if (! $exists)` shape the units and the institution already use.
     */
    public function test_a_reseed_preserves_an_administrators_rename_of_a_level(): void
    {
        $this->seed(ReferenceSeeder::class);

        Level::where('code', 'R1')->update(['name' => 'PGY-1']);

        $this->seed(ReferenceSeeder::class);

        $this->assertSame('PGY-1', Level::where('code', 'R1')->value('name'));
    }
}
