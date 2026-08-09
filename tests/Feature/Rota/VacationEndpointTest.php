<?php

namespace Tests\Feature\Rota;

use App\Models\AuditLog;
use App\Models\Period;
use App\Models\Person;
use App\Models\User;
use App\Models\Vacation;
use App\Support\Calendar;
use Database\Seeders\AccessControlSeeder;
use Database\Seeders\ReferenceSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The HTTP layer over `App\Support\Rota\VacationBooking` (Task 6's writer, already proven
 * directly by `VacationTest`). This file proves booking/cancelling reach the browser correctly:
 * a `week` booking snaps to THIS department's own week (never a hardcoded Sunday), a model-guard
 * refusal is a 422 rather than a raw 500 (P1b finding 14), and every write is audited by ids and
 * dates only.
 */
class VacationEndpointTest extends TestCase
{
    use RefreshDatabase;

    private Person $person;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(ReferenceSeeder::class);
        $this->seed(AccessControlSeeder::class);

        $this->person = Person::factory()->create();
        $this->admin = User::factory()->create(['position' => 0]);
    }

    public function test_a_week_booking_snaps_to_the_departments_own_week(): void
    {
        // 2026-08-12 is a Wednesday; Calendar::weekOf() is the ONE definition of what week that
        // falls in — the test asserts against it, never a literal date, so a future change to
        // the department's configured weekend cannot make this pass for the wrong reason.
        $expected = Calendar::weekOf('2026-08-12');

        $this->actingAs($this->admin)->post('/admin/rota/vacations', [
            'person_id' => $this->person->getKey(),
            'starts_on' => '2026-08-12',
            'ends_on' => '2026-08-12',
            'granularity' => Vacation::GRANULARITY_WEEK,
        ])->assertRedirect();

        $vacation = Vacation::query()->where('person_id', $this->person->getKey())->firstOrFail();

        $this->assertSame($expected['starts_on'], $vacation->starts_on->format('Y-m-d'));
        $this->assertSame($expected['ends_on'], $vacation->ends_on->format('Y-m-d'));
    }

    public function test_a_date_booking_is_stored_verbatim(): void
    {
        $this->actingAs($this->admin)->post('/admin/rota/vacations', [
            'person_id' => $this->person->getKey(),
            'starts_on' => '2026-08-12',
            'ends_on' => '2026-08-14',
            'granularity' => Vacation::GRANULARITY_DATE,
        ])->assertRedirect();

        $vacation = Vacation::query()->where('person_id', $this->person->getKey())->firstOrFail();

        $this->assertSame('2026-08-12', $vacation->starts_on->format('Y-m-d'));
        $this->assertSame('2026-08-14', $vacation->ends_on->format('Y-m-d'));
    }

    public function test_an_overlapping_booking_for_the_same_person_is_a_422_not_a_500(): void
    {
        Vacation::factory()->create([
            'person_id' => $this->person->getKey(),
            'starts_on' => '2026-08-12',
            'ends_on' => '2026-08-14',
        ]);

        $response = $this->actingAs($this->admin)
            ->withHeaders(['X-Inertia' => 'true', 'Accept' => 'application/json'])
            ->post('/admin/rota/vacations', [
                'person_id' => $this->person->getKey(),
                'starts_on' => '2026-08-13',
                'ends_on' => '2026-08-20',
                'granularity' => Vacation::GRANULARITY_DATE,
            ]);

        $response->assertStatus(422);
        $this->assertSame(1, Vacation::query()->where('person_id', $this->person->getKey())->count());
    }

    public function test_a_booking_is_audited_with_ids_and_dates_only(): void
    {
        $before = AuditLog::query()->count();

        $this->actingAs($this->admin)->post('/admin/rota/vacations', [
            'person_id' => $this->person->getKey(),
            'starts_on' => '2026-08-12',
            'ends_on' => '2026-08-14',
            'granularity' => Vacation::GRANULARITY_DATE,
        ])->assertRedirect();

        $this->assertSame($before + 1, AuditLog::query()->count());

        $row = AuditLog::query()->latest('id')->first();

        $this->assertSame('vacation_book', $row->action);
        $this->assertSame(
            "person={$this->person->getKey()};from=2026-08-12;to=2026-08-14;granularity=date",
            $row->detail,
        );
        $this->assertStringNotContainsString($this->person->full_name, (string) $row->detail);
    }

    public function test_a_cancellation_is_audited(): void
    {
        $vacation = Vacation::factory()->create([
            'person_id' => $this->person->getKey(),
            'starts_on' => '2026-08-12',
            'ends_on' => '2026-08-14',
        ]);

        $before = AuditLog::query()->count();

        $this->actingAs($this->admin)->delete("/admin/rota/vacations/{$vacation->getKey()}")
            ->assertRedirect();

        $this->assertSame($before + 1, AuditLog::query()->count());
        $this->assertSame(0, Vacation::query()->whereKey($vacation->getKey())->count());

        $row = AuditLog::query()->latest('id')->first();
        $this->assertSame('vacation_cancel', $row->action);
    }

    public function test_a_person_on_leave_shows_the_leave_on_every_period_cell_it_intersects(): void
    {
        $periodA = Period::factory()->create([
            'academic_year' => '2026-2027', 'position' => 1, 'label' => 'Block 1',
            'starts_on' => '2026-08-01', 'ends_on' => '2026-08-14',
        ]);
        $periodB = Period::factory()->create([
            'academic_year' => '2026-2027', 'position' => 2, 'label' => 'Block 2',
            'starts_on' => '2026-08-15', 'ends_on' => '2026-08-28',
        ]);

        $this->actingAs($this->admin)->post('/admin/rota/vacations', [
            'person_id' => $this->person->getKey(),
            'starts_on' => '2026-08-14',
            'ends_on' => '2026-08-16',
            'granularity' => Vacation::GRANULARITY_DATE,
        ])->assertRedirect();

        $captured = null;
        $this->actingAs($this->admin)->get('/admin/rota?year=2026-2027')->assertOk()
            ->assertInertia(function (\Inertia\Testing\AssertableInertia $page) use (&$captured): void {
                $captured = $page->toArray()['props']['grid'];
            });

        $row = collect($captured['rows'])->firstWhere('person.id', $this->person->getKey());

        $this->assertCount(1, $row['cells'][$periodA->getKey()]['vacations']);
        $this->assertCount(1, $row['cells'][$periodB->getKey()]['vacations']);
    }
}
