<?php

namespace Tests\Feature\Endorsement;

use App\Models\Handover;
use App\Models\Unit;
use App\Models\User;
use Database\Seeders\AccessControlSeeder;
use Database\Seeders\ReferenceSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

/**
 * Phase 4 — all FOUR units are first-class (spec §3). Each has its own day index, sheet,
 * and per-unit writable identity columns, all unit-partitioned; unknown units 404; rows of
 * a unit outside the four-profile surface are unreachable through the bare-ID verbs.
 */
class UnitScopeTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(ReferenceSeeder::class);
        $this->seed(AccessControlSeeder::class);
    }

    private function editor(): User
    {
        return User::factory()->create(['position' => 2]);
    }

    private function rowFor(string $code, string $date = '2026-07-10', array $overrides = []): Handover
    {
        $unit = Unit::where('code', $code)->firstOrFail();

        return Handover::create(array_merge([
            'unit_id' => $unit->id,
            'handover_date' => $date,
            'bed' => '1',
            'mrn' => 'M-'.fake()->unique()->numerify('#####'),
            'patient_name' => 'Test Child',
        ], $overrides));
    }

    public function test_every_unit_serves_its_own_index_sheet_and_print(): void
    {
        $editor = $this->editor();

        foreach (['PICU', 'NICU', 'SCBU', 'WARD', 'picu', 'nicu', 'scbu', 'ward'] as $code) {
            $this->actingAs($editor)->get('/endorsement/'.$code)->assertOk();
            $this->actingAs($editor)->get('/endorsement/'.$code.'/2026-07-10')->assertOk();
        }
    }

    public function test_unknown_unit_codes_still_404(): void
    {
        $editor = $this->editor();

        foreach (['ICU', 'XX', 'rows', 'ER'] as $code) {
            $this->actingAs($editor)->get('/endorsement/'.$code)->assertNotFound();
            $this->actingAs($editor)->post('/endorsement/'.$code.'/new-day', [])->assertNotFound();
        }
    }

    public function test_the_sheet_carries_the_unit_profile(): void
    {
        $this->rowFor('WARD');

        $this->actingAs($this->editor())
            ->get('/endorsement/WARD/2026-07-10')
            ->assertInertia(fn (Assert $page) => $page
                ->component('Endorsement/Sheet')
                ->where('unit.code', 'WARD')
                ->where('unit.profile.bed_label', 'Room')
                ->where('unit.profile.extra_row_fields', ['age', 'ward_unit'])
                ->where('unit.profile.consultant_pair', false)
                ->where('unit.profile.consultant_by_label', 'Consultant Oncall')
            );
    }

    public function test_nicu_accepts_dob_and_rejects_ward_fields(): void
    {
        $this->actingAs($this->editor())
            ->post('/endorsement/NICU/2026-07-10/rows', [
                'mrn' => 'N-1',
                'dob' => '2026-07-01 03:20',
                'age' => '3 days',
                'ward_unit' => 'B',
            ])
            ->assertRedirect();

        $row = Handover::latest('id')->get()->firstWhere('mrn', 'N-1');
        $this->assertNotNull($row->dob);
        $this->assertSame('2026-07-01 03:20', $row->dob->format('Y-m-d H:i'));
        $this->assertNull($row->age, 'age is a WARD-only field');
        $this->assertNull($row->ward_unit, 'ward_unit is a WARD-only field');
    }

    public function test_ward_accepts_age_and_sub_unit_and_rejects_dob(): void
    {
        $this->actingAs($this->editor())
            ->post('/endorsement/WARD/2026-07-10/rows', [
                'mrn' => 'W-1',
                'age' => '4 years',
                'ward_unit' => 'Hematology',
                'dob' => '2022-01-01 00:00',
            ])
            ->assertRedirect();

        $row = Handover::latest('id')->get()->firstWhere('mrn', 'W-1');
        $this->assertSame('4 years', $row->age);
        $this->assertSame('Hematology', $row->ward_unit);
        $this->assertNull($row->dob, 'dob is a NICU/SCBU-only field');
    }

    public function test_updating_a_row_uses_its_own_units_profile(): void
    {
        $row = $this->rowFor('SCBU');

        $this->actingAs($this->editor())
            ->patch('/endorsement/rows/'.$row->id, ['dob' => '2026-06-15 08:00'])
            ->assertRedirect();

        $this->assertSame('2026-06-15 08:00', $row->fresh()->dob->format('Y-m-d H:i'));
    }

    public function test_rows_of_a_unit_outside_the_surface_are_unreachable_via_bare_id_verbs(): void
    {
        $extra = Unit::create(['code' => 'XX', 'name' => 'Somewhere Else']);
        $row = Handover::create([
            'unit_id' => $extra->id,
            'handover_date' => '2026-07-10',
            'mrn' => 'XX-1',
        ]);

        $this->actingAs($this->editor())->patch('/endorsement/rows/'.$row->id, ['plan' => 'x'])->assertNotFound();
        $this->actingAs($this->editor())->delete('/endorsement/rows/'.$row->id)->assertNotFound();
        $this->assertNull($row->fresh()->deleted_at);
    }

    public function test_the_day_index_is_unit_partitioned(): void
    {
        $this->rowFor('NICU', '2026-07-10');
        $this->rowFor('SCBU', '2026-07-11');

        $this->actingAs($this->editor())
            ->get('/endorsement/NICU')
            ->assertInertia(fn (Assert $page) => $page
                ->component('Endorsement/Index')
                ->where('unit.code', 'NICU')
                ->has('dates', 1)
                ->where('dates.0.date', '2026-07-10')
            );
    }

    public function test_the_chooser_lists_all_four_units_with_today_status(): void
    {
        $today = now()->format('Y-m-d');
        $this->rowFor('PICU', $today);

        $this->actingAs($this->editor())
            ->get('/endorsement')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Endorsement/Chooser')
                ->has('units', 4)
                ->where('units.0.code', 'PICU')
                ->where('units.0.today.has_sheet', true)
                ->where('units.0.today.rows', 1)
                ->where('units.0.today.signed_off', false)
                ->where('units.1.code', 'NICU')
                ->where('units.1.today.has_sheet', false)
            );
    }
}
