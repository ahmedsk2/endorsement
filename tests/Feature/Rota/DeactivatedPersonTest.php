<?php

namespace Tests\Feature\Rota;

use App\Models\MasterRotaAssignment;
use App\Models\Period;
use App\Models\Person;
use App\Models\Unit;
use App\Models\User;
use App\Support\LevelAssignment;
use App\Support\Rota\RotaAssignment;
use Database\Seeders\AccessControlSeeder;
use Database\Seeders\ReferenceSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

/**
 * Pre-merge finding 1. People are DEACTIVATED, never deleted (standing owner ruling), so
 * "assigned, then deactivated" is a state this system reaches on its own — a resident who leaves
 * mid-year keeps every span already planned for them.
 *
 * Before this file, that state was a dead end in both directions at once:
 *  - `RotaGrid::forYear()` built its rows from `Person::query()->active()`, so the row vanished;
 *  - `RotaCellRequest` applied the same active-exists predicate to the DELETE (clear) route it
 *    applies to the two write routes, so the assignment could not be removed by id either.
 * The assignment then blocked `PeriodController::destroy()` forever, and with it Decision D's
 * unlock of `period_type`/`academic_year_start` — whose own refusal message tells the operator to
 * "clear the rota for this year first (Master Rota)". The only remaining remedy was DB surgery,
 * which CLAUDE.md reserves for the owner.
 *
 * Offer/write parity (the 2026-07-26 audit's rule) governs what may be CREATED, not what may be
 * REMOVED: the pickers still offer active people only, and set/split still refuse an inactive
 * person, but clearing a span that already exists is never an act of naming somebody.
 */
class DeactivatedPersonTest extends TestCase
{
    use RefreshDatabase;

    private Period $period;

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

        $this->unit = Unit::create(['code' => 'DPA', 'name' => 'Deactivated Person A', 'active' => true]);
        $this->admin = User::factory()->create(['position' => 0]);
    }

    public function test_a_deactivated_person_who_still_holds_an_assignment_keeps_a_grid_row(): void
    {
        $person = $this->assignedThenDeactivated();

        $row = $this->rowFor($this->fetchGrid(), $person);

        $this->assertTrue($row['stale'], 'the row must be flagged so the client can render it read-only');
        $this->assertCount(1, $row['cells'][$this->period->getKey()]['spans']);
        // The flag lives on the ROW, not inside the person projection — PersonPresenter projects a
        // person, and "this row is only here because it still holds a span" is a fact about the grid.
        $this->assertArrayNotHasKey('stale', $row['person']);
    }

    public function test_a_soft_deleted_person_who_still_holds_an_assignment_keeps_a_grid_row(): void
    {
        $person = $this->assignedThenDeactivated();
        $person->delete();

        $row = $this->rowFor($this->fetchGrid(), $person);

        $this->assertTrue($row['stale']);
        $this->assertTrue($row['person']['retired']);
    }

    public function test_a_deactivated_person_with_no_assignment_is_still_absent(): void
    {
        $person = Person::factory()->inactive()->create();

        $ids = array_map(fn (array $row) => $row['person']['id'], $this->fetchGrid()['rows']);

        $this->assertNotContains($person->getKey(), $ids);
    }

    public function test_an_active_persons_row_is_not_flagged_stale(): void
    {
        $person = Person::factory()->create();
        RotaAssignment::set($person, $this->period, $this->unit);

        $this->assertFalse($this->rowFor($this->fetchGrid(), $person)['stale']);
    }

    public function test_clearing_a_deactivated_persons_cell_succeeds_and_unwedges_the_academic_year(): void
    {
        $person = $this->assignedThenDeactivated();

        // Before: the year cannot be deleted, so Decision D's unlock is unreachable.
        $this->deleteYear()->assertSessionHasErrors('confirm_academic_year');

        $this->actingAs($this->admin)
            ->delete('/admin/rota/cell', [
                'person_id' => $person->getKey(),
                'period_id' => $this->period->getKey(),
            ])
            ->assertSessionHasNoErrors();

        $this->assertSame(0, MasterRotaAssignment::query()->where('person_id', $person->getKey())->count());

        $this->deleteYear()->assertSessionHasNoErrors();
        $this->assertSame(0, Period::query()->where('academic_year', '2026-2027')->count());
    }

    public function test_clearing_a_soft_deleted_persons_cell_succeeds(): void
    {
        $person = $this->assignedThenDeactivated();
        $person->delete();

        $this->actingAs($this->admin)
            ->delete('/admin/rota/cell', [
                'person_id' => $person->getKey(),
                'period_id' => $this->period->getKey(),
            ])
            ->assertSessionHasNoErrors();

        $this->assertSame(0, MasterRotaAssignment::query()->where('person_id', $person->getKey())->count());
    }

    public function test_a_deactivated_person_cannot_be_assigned_to(): void
    {
        $person = $this->assignedThenDeactivated();

        $this->actingAs($this->admin)
            ->patch('/admin/rota/cell', [
                'person_id' => $person->getKey(),
                'period_id' => $this->period->getKey(),
                'unit_id' => $this->unit->getKey(),
            ])
            ->assertSessionHasErrors('person_id');
    }

    public function test_a_deactivated_person_cannot_be_split(): void
    {
        $person = $this->assignedThenDeactivated();

        $this->actingAs($this->admin)
            ->post('/admin/rota/cell/split', [
                'person_id' => $person->getKey(),
                'period_id' => $this->period->getKey(),
                'spans' => [
                    ['unit_id' => $this->unit->getKey(), 'starts_on' => '2026-07-01', 'ends_on' => '2026-07-14'],
                ],
            ])
            ->assertSessionHasErrors('person_id');
    }

    /** Assign a person for the whole period, then deactivate them — the state this file is about. */
    private function assignedThenDeactivated(): Person
    {
        $person = Person::factory()->create();
        LevelAssignment::assign($person, \App\Models\Level::query()->orderBy('display_order')->firstOrFail(), '2026-07-01');
        RotaAssignment::set($person, $this->period, $this->unit);

        $person->forceFill(['active' => false])->save();

        return $person;
    }

    private function deleteYear(): \Illuminate\Testing\TestResponse
    {
        return $this->actingAs($this->admin)
            ->delete('/admin/structure/periods/2026-2027', ['confirm_academic_year' => '2026-2027']);
    }

    /** @return array<string, mixed> */
    private function fetchGrid(): array
    {
        $captured = null;

        $this->actingAs($this->admin)->get('/admin/rota?year=2026-2027')->assertOk()
            ->assertInertia(function (Assert $page) use (&$captured): void {
                $captured = $page->toArray()['props']['grid'];
            });

        $this->assertIsArray($captured, 'the grid prop was not an array — is the year query string wrong?');

        return $captured;
    }

    /** @return array<string, mixed> */
    private function rowFor(array $grid, Person $person): array
    {
        foreach ($grid['rows'] as $row) {
            if ($row['person']['id'] === $person->getKey()) {
                return $row;
            }
        }

        $this->fail("No row found for person {$person->getKey()}.");
    }
}
