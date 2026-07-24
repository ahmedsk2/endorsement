<?php

namespace Tests\Feature\Endorsement;

use App\Models\Handover;
use App\Models\HandoverSignoff;
use App\Models\Unit;
use App\Models\User;
use App\Support\MissedDays;
use Database\Seeders\AccessControlSeeder;
use Database\Seeders\ReferenceSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

/**
 * Spec §10.3 — the missed-days view, the system's ONLY aggregate. A day is MISSED when the
 * unit has no SIGNED sign-off for that date; the expansion distinguishes "no sheet at all"
 * from "sheet created but never signed". Counts and dates only — never patient data.
 */
class MissedDaysTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(ReferenceSeeder::class);
        $this->seed(AccessControlSeeder::class);
    }

    private function unit(string $code): Unit
    {
        return Unit::where('code', $code)->firstOrFail();
    }

    private function sheet(string $code, string $date): void
    {
        Handover::create([
            'unit_id' => $this->unit($code)->id,
            'handover_date' => $date,
            'mrn' => 'M-'.fake()->unique()->numerify('#####'),
        ]);
    }

    private function signed(string $code, string $date): void
    {
        $this->sheet($code, $date);
        HandoverSignoff::create([
            'unit_id' => $this->unit($code)->id,
            'handover_date' => $date,
            'signed_off_at' => $date.' 14:00:00',
        ]);
    }

    // ------------------------------------------------------------- the maths

    public function test_a_day_is_missed_unless_a_signed_signoff_exists(): void
    {
        $this->signed('PICU', '2026-07-01');   // signed -> not missed
        $this->sheet('PICU', '2026-07-02');    // sheet, never signed -> missed (unsigned)
        // 2026-07-03: nothing at all -> missed (no_sheet)

        $result = MissedDays::forRange($this->unit('PICU')->id, '2026-07-01', '2026-07-03');

        $this->assertSame(3, $result['total_days']);
        $this->assertSame([
            ['date' => '2026-07-02', 'kind' => 'unsigned'],
            ['date' => '2026-07-03', 'kind' => 'no_sheet'],
        ], $result['missed']);
    }

    public function test_the_range_is_inclusive_on_both_ends(): void
    {
        $result = MissedDays::forRange($this->unit('PICU')->id, '2026-07-01', '2026-07-01');

        $this->assertSame(1, $result['total_days']);
        $this->assertCount(1, $result['missed']);
    }

    public function test_future_days_are_excluded(): void
    {
        $from = now()->subDay()->format('Y-m-d');
        $to = now()->addDays(5)->format('Y-m-d');

        $result = MissedDays::forRange($this->unit('PICU')->id, $from, $to);

        // Yesterday + today only; the five future days are not "missed", they have not happened.
        $this->assertSame(2, $result['total_days']);
    }

    public function test_the_computation_is_unit_partitioned(): void
    {
        $this->signed('NICU', '2026-07-01');

        $picu = MissedDays::forRange($this->unit('PICU')->id, '2026-07-01', '2026-07-01');
        $nicu = MissedDays::forRange($this->unit('NICU')->id, '2026-07-01', '2026-07-01');

        $this->assertCount(1, $picu['missed']);
        $this->assertCount(0, $nicu['missed']);
    }

    public function test_a_reopened_day_counts_as_missed_again(): void
    {
        $this->sheet('PICU', '2026-07-01');
        HandoverSignoff::create([
            'unit_id' => $this->unit('PICU')->id,
            'handover_date' => '2026-07-01',
            'signed_off_at' => null,          // reopened: the stamp is cleared
            'reopened_at' => '2026-07-02 08:00:00',
        ]);

        $result = MissedDays::forRange($this->unit('PICU')->id, '2026-07-01', '2026-07-01');

        $this->assertSame([['date' => '2026-07-01', 'kind' => 'unsigned']], $result['missed']);
    }

    // ------------------------------------------------------------- the page

    public function test_the_page_requires_the_compliance_capability(): void
    {
        // Charge Nurse holds endorsement.view+edit but NOT endorsement.compliance.
        $editor = User::factory()->create(['position' => 2]);

        $this->actingAs($editor)->get('/endorsement/compliance')->assertForbidden();
    }

    public function test_the_page_reports_counts_and_dates_per_unit(): void
    {
        $admin = User::factory()->create(['position' => 0]);
        $this->signed('PICU', now()->subDays(2)->format('Y-m-d'));

        $this->actingAs($admin)
            ->get('/endorsement/compliance?from='.now()->subDays(2)->format('Y-m-d').'&to='.now()->subDays(1)->format('Y-m-d'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Endorsement/Compliance')
                ->has('units', 4)
                ->where('units.0.code', 'PICU')
                ->where('units.0.total_days', 2)
                ->has('units.0.missed', 1)
                ->where('units.0.missed.0.kind', 'no_sheet')
            );
    }

    public function test_the_default_range_is_the_last_30_days(): void
    {
        $admin = User::factory()->create(['position' => 0]);

        $this->actingAs($admin)
            ->get('/endorsement/compliance')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('filters.from', now()->subDays(29)->format('Y-m-d'))
                ->where('filters.to', now()->format('Y-m-d'))
            );
    }

    public function test_the_payload_carries_no_patient_data(): void
    {
        $admin = User::factory()->create(['position' => 0]);
        $this->sheet('PICU', now()->subDay()->format('Y-m-d'));
        Handover::query()->update(['patient_name' => 'Secret Child', 'mrn' => 'SECRET-1']);

        $response = $this->actingAs($admin)->get('/endorsement/compliance');

        $this->assertStringNotContainsString('Secret Child', $response->getContent());
        $this->assertStringNotContainsString('SECRET-1', $response->getContent());
    }
}
