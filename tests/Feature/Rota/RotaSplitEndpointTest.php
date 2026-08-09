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
 * The HTTP layer over `App\Support\Rota\RotaAssignment::split()` (Task 8's writer, already
 * proven directly by `RotaAssignmentWriterTest`). This file proves the CONTROLLER/REQUEST side:
 * a model-guard/writer refusal reaches the browser as a 422 naming the offending field — never a
 * raw 500 (P1b finding 14) — and the previous good state survives a rejected split byte-for-byte.
 */
class RotaSplitEndpointTest extends TestCase
{
    use RefreshDatabase;

    private Period $period;

    private Person $person;

    private Unit $unitA;

    private Unit $unitB;

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
        $this->unitA = Unit::create(['code' => 'RSA', 'name' => 'Rota Split A', 'active' => true]);
        $this->unitB = Unit::create(['code' => 'RSB', 'name' => 'Rota Split B', 'active' => true]);
        $this->admin = User::factory()->create(['position' => 0]);
    }

    private function postSplit(array $spans): \Illuminate\Testing\TestResponse
    {
        return $this->actingAs($this->admin)
            ->withHeaders(['X-Inertia' => 'true', 'Accept' => 'application/json'])
            ->post('/admin/rota/cell/split', [
                'person_id' => $this->person->getKey(),
                'period_id' => $this->period->getKey(),
                'spans' => $spans,
            ]);
    }

    public function test_a_two_span_split_persists_two_rows(): void
    {
        $this->postSplit([
            ['unit_id' => $this->unitA->getKey(), 'starts_on' => '2026-07-01', 'ends_on' => '2026-07-14'],
            ['unit_id' => $this->unitB->getKey(), 'starts_on' => '2026-07-15', 'ends_on' => '2026-07-28'],
        ])->assertRedirect();

        $this->assertCount(2, MasterRotaAssignment::query()
            ->where('person_id', $this->person->getKey())
            ->where('period_id', $this->period->getKey())
            ->get());
    }

    public function test_a_split_whose_spans_overlap_returns_a_422_naming_the_field_and_leaves_the_previous_assignment_untouched(): void
    {
        RotaAssignment::set($this->person, $this->period, $this->unitA);

        $before = MasterRotaAssignment::query()
            ->where('person_id', $this->person->getKey())
            ->where('period_id', $this->period->getKey())
            ->get()
            ->toArray();

        $response = $this->postSplit([
            ['unit_id' => $this->unitA->getKey(), 'starts_on' => '2026-07-01', 'ends_on' => '2026-07-14'],
            ['unit_id' => $this->unitB->getKey(), 'starts_on' => '2026-07-10', 'ends_on' => '2026-07-28'],
        ]);

        $response->assertStatus(422);
        $response->assertJsonValidationErrors('spans');

        $after = MasterRotaAssignment::query()
            ->where('person_id', $this->person->getKey())
            ->where('period_id', $this->period->getKey())
            ->get()
            ->toArray();

        $this->assertSame($before, $after);
    }

    public function test_a_split_reaching_outside_its_period_is_a_422(): void
    {
        $response = $this->postSplit([
            ['unit_id' => $this->unitA->getKey(), 'starts_on' => '2026-06-30', 'ends_on' => '2026-07-28'],
        ]);

        $response->assertStatus(422);
        $response->assertJsonValidationErrors('spans');
    }

    public function test_a_split_with_a_retired_unit_is_a_422(): void
    {
        $retired = Unit::create(['code' => 'RSR', 'name' => 'Rota Split Retired', 'active' => false]);

        $response = $this->postSplit([
            ['unit_id' => $retired->getKey(), 'starts_on' => '2026-07-01', 'ends_on' => '2026-07-14'],
            ['unit_id' => $this->unitB->getKey(), 'starts_on' => '2026-07-15', 'ends_on' => '2026-07-28'],
        ]);

        $response->assertStatus(422);
        $response->assertJsonValidationErrors('spans.0.unit_id');
    }

    public function test_a_split_of_one_span_collapses_to_the_same_shape_set_produces(): void
    {
        $this->postSplit([
            ['unit_id' => $this->unitA->getKey(), 'starts_on' => '2026-07-01', 'ends_on' => '2026-07-28'],
        ])->assertRedirect();

        $rows = MasterRotaAssignment::query()
            ->where('person_id', $this->person->getKey())
            ->where('period_id', $this->period->getKey())
            ->get();

        $this->assertCount(1, $rows);
        $this->assertSame('2026-07-01', $rows[0]->starts_on->format('Y-m-d'));
        $this->assertSame('2026-07-28', $rows[0]->ends_on->format('Y-m-d'));
    }

    public function test_a_split_is_audited_once_with_no_name_in_the_detail(): void
    {
        $before = AuditLog::query()->count();

        $this->postSplit([
            ['unit_id' => $this->unitA->getKey(), 'starts_on' => '2026-07-01', 'ends_on' => '2026-07-14'],
            ['unit_id' => $this->unitB->getKey(), 'starts_on' => '2026-07-15', 'ends_on' => '2026-07-28'],
        ])->assertRedirect();

        $this->assertSame($before + 1, AuditLog::query()->count());

        $row = AuditLog::query()->latest('id')->first();

        $this->assertSame('rota_split', $row->action);
        $this->assertSame(
            "person={$this->person->getKey()};period={$this->period->getKey()};spans=2",
            $row->detail,
        );
        $this->assertStringNotContainsString($this->person->full_name, (string) $row->detail);
    }
}
