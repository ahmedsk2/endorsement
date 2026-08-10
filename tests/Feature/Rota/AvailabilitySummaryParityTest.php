<?php

namespace Tests\Feature\Rota;

use App\Models\Level;
use App\Models\Period;
use App\Models\Person;
use App\Models\Unit;
use App\Models\User;
use App\Models\Vacation;
use App\Support\LevelAssignment;
use App\Support\Rota\RotaAssignment;
use App\Support\Rota\VacationBooking;
use Carbon\CarbonImmutable;
use Database\Seeders\AccessControlSeeder;
use Database\Seeders\ReferenceSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

/**
 * MR-07 is ONE summary (P1d-2 Decision B), and this is where "one" stops being a claim in a
 * docblock and becomes a property two live requests have to satisfy.
 *
 * The editor (`/admin/rota`, `cap:rota.manage`) and the read view (`/rota`, `cap:rota.view`) build
 * their own grid, from their own controller, for their own viewer. The two grids differ in exactly
 * one respect: the read view filters stale rows for DISPLAY, after the summary is computed
 * (Decision D's ordering trap). Everything else about the numbers must be identical — a department
 * that reads "four people on PICU in Block 3" on one screen and "three" on the other has no way to
 * know which one is the rota, and Munawib §35's acceptance criterion is that the summaries match
 * reality, singular.
 *
 * IT COMPARES TWO RESPONSES, NOT TWO CALLS TO ONE FUNCTION. Calling
 * `AvailabilitySummary::forGrid()` twice in a test and asserting the results agree proves only that
 * PHP is deterministic; it would stay green through a controller that never rendered the prop at
 * all, or rendered a hand-rolled second version of it. So both props are read off real Inertia
 * responses, and `test_the_editor_actually_ships_a_summary_prop` guards the degenerate reading
 * where two nulls are trivially the same.
 *
 * THE FIXTURE IS DELIBERATELY NON-TRIVIAL, for the same reason. Two empty summaries are equal, so
 * the year below carries a split with a gap, a person with no assignment at all, a mid-year
 * promotion, a vacation crossing a week boundary and a person who has left the department — one
 * for each figure `AvailabilitySummary` computes. `test_the_summary_under_comparison_is_not_empty`
 * asserts every one of them is non-zero before the parity assertion means anything.
 */
class AvailabilitySummaryParityTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(ReferenceSeeder::class);
        $this->seed(AccessControlSeeder::class);
    }

    public function test_the_editor_and_the_read_view_report_the_same_summary(): void
    {
        $this->seedYear();

        // BOTH viewers before EITHER request, and this is not tidiness. `User::factory()` mints a
        // `Person` alongside the account, so creating the resident between the two requests puts
        // one more unassigned person on the roster and the second summary legitimately differs —
        // observed, not theorised: the first run of this test failed with `unassigned_people` off
        // by exactly one in every period. The fixture has to be identical for both reads or the
        // assertion measures the fixture rather than the code.
        [$admin, $resident] = $this->viewers();

        $editor = $this->summaryFrom('/admin/rota?year=2026-2027', $admin);
        $read = $this->summaryFrom('/rota?year=2026-2027', $resident);

        $this->assertSame($editor, $read,
            'the editor and the read view reported different MR-07 figures for the same year — '
            .'there are two computations where Decision B allows one');
    }

    /**
     * The half that makes the assertion above non-vacuous from the editor's side. Before this task
     * `/admin/rota` had no `summary` prop at all, so a parity test that only compared the two
     * values would have compared `null` with an array — red, correctly — but the day somebody
     * "fixed" it by deleting the read view's prop instead, the same test would go green on two
     * nulls. This says the editor ships one.
     */
    public function test_the_editor_actually_ships_a_summary_prop(): void
    {
        [$periods] = $this->seedYear();
        [$admin] = $this->viewers();

        $summary = $this->summaryFrom('/admin/rota?year=2026-2027', $admin);

        $this->assertIsArray($summary);
        $this->assertSame(
            array_map(fn (Period $period): int => (int) $period->getKey(), $periods),
            array_keys($summary),
            'the editor summary is not keyed by every period of the year, in the grid order'
        );
    }

    /**
     * Two empty arrays are identical, so this asserts the fixture actually exercises every figure
     * MR-07 defines before the parity case is allowed to mean anything.
     */
    public function test_the_summary_under_comparison_is_not_empty(): void
    {
        [$periods] = $this->seedYear();
        [, $resident] = $this->viewers();

        $summary = $this->summaryFrom('/rota?year=2026-2027', $resident);
        $first = $summary[(int) $periods[0]->getKey()];
        $last = $summary[(int) $periods[2]->getKey()];

        $this->assertGreaterThan(0, $first['assigned_days']);
        $this->assertGreaterThan(0, $first['uncovered_days']);
        $this->assertGreaterThan(0, $first['people_with_a_gap']);
        $this->assertGreaterThan(0, $first['stale_assignments']);
        $this->assertGreaterThan(0, $last['unassigned_people']);
        $this->assertGreaterThan(0, array_sum(array_column($first['weeks'], 'on_vacation')));

        // The mid-year promotion: the same person buckets under two different levels across the
        // year, which is the figure a summary keyed on the ROW's group level would get wrong.
        $this->assertNotSame(array_keys($first['by_level_unit']), array_keys($last['by_level_unit']),
            'the fixture never actually promotes anybody across a period boundary');
    }

    /**
     * Decision D from the other side. The read view HIDES the stale row and the editor SHOWS it —
     * that is a real, deliberate difference between the two screens — and the summary must still
     * be identical, because it is computed before either screen narrows anything. If this ever
     * fails while the parity case passes, the summary has started depending on the row list.
     */
    public function test_the_row_lists_differ_while_the_summary_does_not(): void
    {
        $this->seedYear();
        [$admin, $resident] = $this->viewers();

        $editor = $this->propsFrom('/admin/rota?year=2026-2027', $admin);
        $read = $this->propsFrom('/rota?year=2026-2027', $resident);

        $this->assertNotSame($this->rowIdsOf($editor), $this->rowIdsOf($read),
            'the two screens showed the same rows — the stale row is missing from the fixture, so '
            .'this test is not exercising the difference it exists to hold constant against');
        $this->assertSame($editor['summary'], $read['summary']);
    }

    /**
     * One administrator and one resident, both created BEFORE any request is made. See the comment
     * in the parity case: an account minted between two reads changes the roster under the second.
     *
     * @return array{0: User, 1: User}
     */
    private function viewers(): array
    {
        return [
            User::factory()->create(['position' => 0]),
            User::factory()->create(['position' => 4]),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function summaryFrom(string $url, User $viewer): array
    {
        return $this->propsFrom($url, $viewer)['summary'];
    }

    /**
     * @param  array<string, mixed>  $props
     * @return list<int>
     */
    private function rowIdsOf(array $props): array
    {
        return array_map(fn (array $row): int => $row['person']['id'], $props['grid']['rows']);
    }

    /**
     * @return array<string, mixed>
     */
    private function propsFrom(string $url, User $viewer): array
    {
        $captured = null;

        $this->actingAs($viewer)->get($url)->assertOk()
            ->assertInertia(function (Assert $page) use (&$captured): void {
                $captured = $page->toArray()['props'];
            });

        $this->assertIsArray($captured);

        return $captured;
    }

    /**
     * Three four-week blocks and five people, shaped so every MR-07 figure is non-zero:
     *
     *  - Anwar   whole-period on unit A throughout, PROMOTED to the second level at block 2's start
     *  - Basma   split in block 1 with a four-day gap, plus a vacation crossing a week boundary
     *  - Camila  whole-period on unit B in blocks 1 and 2, nothing at all in block 3
     *  - Dalia   no level history whatsoever — the NO_LEVEL bucket
     *  - Emad    assigned in block 1, then deactivated — the stale row the read view hides
     *
     * `XP`-prefixed codes throughout: `Level::factory()->create(['code' => 'R1'])` collides with
     * `ReferenceSeeder`'s seeded ladder (P1c Task 3's recorded trap).
     *
     * @return array{0: list<Period>, 1: Unit, 2: Unit}
     */
    private function seedYear(): array
    {
        $junior = Level::factory()->create(['code' => 'XP1', 'display_order' => 10]);
        $senior = Level::factory()->create(['code' => 'XP2', 'display_order' => 20]);
        $unitA = Unit::create(['code' => 'XPA', 'name' => 'Parity Unit A', 'active' => true]);
        $unitB = Unit::create(['code' => 'XPB', 'name' => 'Parity Unit B', 'active' => true]);

        $start = CarbonImmutable::parse('2026-07-01');
        $periods = [];

        for ($i = 1; $i <= 3; $i++) {
            $blockStart = $start->addWeeks(($i - 1) * 4);

            $periods[] = Period::create([
                'academic_year' => '2026-2027',
                'kind' => Period::WEEK_BLOCK,
                'position' => $i,
                'label' => "Block {$i}",
                'starts_on' => $blockStart->format('Y-m-d'),
                'ends_on' => $blockStart->addWeeks(4)->subDay()->format('Y-m-d'),
            ]);
        }

        $anwar = Person::factory()->create(['full_name' => 'Anwar Parity', 'short_name' => 'anwar']);
        LevelAssignment::assign($anwar, $junior, $periods[0]->starts_on->format('Y-m-d'));
        LevelAssignment::assign($anwar, $senior, $periods[1]->starts_on->format('Y-m-d'));

        foreach ($periods as $period) {
            RotaAssignment::set($anwar, $period, $unitA);
        }

        $basma = Person::factory()->create(['full_name' => 'Basma Parity', 'short_name' => 'basma']);
        LevelAssignment::assign($basma, $junior, $periods[0]->starts_on->format('Y-m-d'));
        RotaAssignment::split($basma, $periods[0], [
            ['unit_id' => $unitA->getKey(),
                'starts_on' => $periods[0]->starts_on->format('Y-m-d'),
                'ends_on' => $periods[0]->starts_on->addDays(9)->format('Y-m-d')],
            ['unit_id' => $unitB->getKey(),
                'starts_on' => $periods[0]->starts_on->addDays(14)->format('Y-m-d'),
                'ends_on' => $periods[0]->ends_on->format('Y-m-d')],
        ]);
        VacationBooking::book(
            $basma,
            $periods[0]->starts_on->addDays(5)->format('Y-m-d'),
            $periods[0]->starts_on->addDays(9)->format('Y-m-d'),
            Vacation::GRANULARITY_DATE,
        );

        $camila = Person::factory()->create(['full_name' => 'Camila Parity', 'short_name' => 'camila']);
        LevelAssignment::assign($camila, $senior, $periods[0]->starts_on->format('Y-m-d'));
        RotaAssignment::set($camila, $periods[0], $unitB);
        RotaAssignment::set($camila, $periods[1], $unitB);

        // No level span at all — AvailabilitySummary::NO_LEVEL's own bucket.
        $dalia = Person::factory()->create(['full_name' => 'Dalia Parity', 'short_name' => 'dalia']);
        RotaAssignment::set($dalia, $periods[0], $unitA);

        $emad = Person::factory()->create(['full_name' => 'Emad Parity', 'short_name' => 'emad']);
        LevelAssignment::assign($emad, $junior, $periods[0]->starts_on->format('Y-m-d'));
        RotaAssignment::set($emad, $periods[0], $unitA);
        $emad->forceFill(['active' => false])->save();

        return [$periods, $unitA, $unitB];
    }
}
