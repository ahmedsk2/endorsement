<?php

namespace Tests\Feature\Rota;

use App\Models\AuditLog;
use App\Models\MasterRotaAssignment;
use App\Models\Period;
use App\Models\Person;
use App\Models\Unit;
use App\Models\User;
use App\Support\Rota\RotaAssignment;
use Database\Seeders\AccessControlSeeder;
use Database\Seeders\ReferenceSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The HTTP layer over `App\Support\Rota\RotaAssignment::clear()` (Task 5's writer, already
 * proven directly by `RotaAssignmentWriterTest`). `DELETE /admin/rota/cell` was registered and
 * delegated to in Task 8 (its own "Deviation from the Files list" note: all five `/admin/rota/*`
 * write routes were wired in one commit so none resolves against a missing controller method) but
 * never exercised by an HTTP-level test — this file is that missing coverage, added alongside
 * Task 11's e2e journey once the grid's own UI needed a Clear control to test through (see this
 * task's own Amendments entry: neither Task 8 nor Task 9 ever specified building one, despite
 * Task 9's "Remove span" tooltip naming Clear as the way to empty a cell).
 */
class RotaClearEndpointTest extends TestCase
{
    use RefreshDatabase;

    private Period $period;

    private Person $person;

    private Unit $unit;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(ReferenceSeeder::class);
        $this->seed(AccessControlSeeder::class);

        $this->period = Period::factory()->create([
            'academic_year' => '2026-2027',
            'kind' => Period::WEEK_BLOCK,
            'position' => 1,
            'label' => 'Block 1',
            'starts_on' => '2026-07-01',
            'ends_on' => '2026-07-28',
        ]);

        $this->person = Person::factory()->create();
        $this->unit = Unit::create(['code' => 'RCA', 'name' => 'Rota Clear A', 'active' => true]);
        $this->admin = User::factory()->create(['position' => 0]);
    }

    private function deleteCell(): \Illuminate\Testing\TestResponse
    {
        return $this->actingAs($this->admin)
            ->withHeaders(['X-Inertia' => 'true', 'Accept' => 'application/json'])
            ->delete('/admin/rota/cell', [
                'person_id' => $this->person->getKey(),
                'period_id' => $this->period->getKey(),
            ]);
    }

    public function test_clearing_a_simple_assignment_removes_it(): void
    {
        RotaAssignment::set($this->person, $this->period, $this->unit);

        $this->deleteCell()->assertRedirect();

        $this->assertSame(0, MasterRotaAssignment::query()
            ->where('person_id', $this->person->getKey())
            ->where('period_id', $this->period->getKey())
            ->count());
    }

    public function test_clearing_a_split_cell_removes_every_span(): void
    {
        $unitB = Unit::create(['code' => 'RCB', 'name' => 'Rota Clear B', 'active' => true]);

        RotaAssignment::split($this->person, $this->period, [
            ['unit_id' => $this->unit->getKey(), 'starts_on' => '2026-07-01', 'ends_on' => '2026-07-14'],
            ['unit_id' => $unitB->getKey(), 'starts_on' => '2026-07-15', 'ends_on' => '2026-07-28'],
        ]);

        $this->deleteCell()->assertRedirect();

        $this->assertSame(0, MasterRotaAssignment::query()
            ->where('person_id', $this->person->getKey())
            ->where('period_id', $this->period->getKey())
            ->count());
    }

    public function test_clearing_an_already_empty_cell_is_a_no_op_and_writes_no_audit_row(): void
    {
        $before = AuditLog::query()->count();

        $this->deleteCell()->assertRedirect();

        $this->assertSame($before, AuditLog::query()->count());
    }

    public function test_a_clear_that_removed_something_is_audited_with_ids_only(): void
    {
        RotaAssignment::set($this->person, $this->period, $this->unit);

        $before = AuditLog::query()->count();

        $this->deleteCell()->assertRedirect();

        $this->assertSame($before + 1, AuditLog::query()->count());

        $row = AuditLog::query()->latest('id')->first();

        $this->assertSame('rota_clear', $row->action);
        $this->assertSame(
            "person={$this->person->getKey()};period={$this->period->getKey()}",
            $row->detail,
        );
        $this->assertStringNotContainsString($this->person->full_name, (string) $row->detail);
    }

    public function test_a_resident_cannot_reach_the_clear_route(): void
    {
        $resident = User::factory()->create(['position' => 4]);

        $this->actingAs($resident)
            ->delete('/admin/rota/cell', [
                'person_id' => $this->person->getKey(),
                'period_id' => $this->period->getKey(),
            ])
            ->assertForbidden();
    }
}
