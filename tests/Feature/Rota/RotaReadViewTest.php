<?php

namespace Tests\Feature\Rota;

use App\Models\Institution;
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
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

/**
 * Munawib MR-05 — the read view at `/rota`, behind `cap:rota.view`, which every seeded position
 * holds (P1d-1 owner decision 2). Three properties, each asserted rather than assumed.
 *
 * NO PUBLISH GATE (P1d-2 owner decision 1, 2026-08-10). `/rota` always shows the current rota:
 * no `status` column, no `published_at`, no draft state, no "visible from" date. The design doc
 * once listed an explicit "not visible until I say so" gate as an open product option; it is
 * closed, and `test_there_is_no_publish_state_on_the_read_view` is what keeps it closed.
 *
 * CONTACT-FREE FOR EVERY VIEWER (P1d-2 Decision C, closing finding 3). Not "contact-free for a
 * resident" — `test_no_contact_field_reaches_the_props_for_any_viewer` runs the assertion for an
 * ADMINISTRATOR on a department set to `contact_visibility = members`, which is the single most
 * permissive combination this system can produce: `PersonPolicy::viewContact()` is
 * `people.manage OR membersMaySeeContact()`, so both branches are true at once there. A test
 * written against a resident on the default setting would pass with the defect fully present.
 *
 * The defect was real and it was in the EDITOR, before this read view existed:
 * `RotaGrid::forYear()` passed the request's user to `PersonPresenter::one()`, so on a department
 * that had opted members into contact detail, `/admin/rota` shipped every colleague's email and
 * phone number in its Inertia props. Nothing rendered them — which is worse, not better, because
 * a payload disclosure that no screen displays is invisible to review. Hence
 * `test_the_editor_grid_is_contact_free_too`: the fix lands on both surfaces from one edit, and
 * the surface that was already leaking gets its own case.
 */
class RotaReadViewTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Props every Inertia page carries from `HandleInertiaRequests::share()`, regardless of the
     * screen. Excluded from the publish-state scan only — `flash.status` is the layout's one-shot
     * banner channel and has nothing to do with a rota's state, and a scan that flagged it would
     * be a guard nobody could keep green. The CONTACT scan deliberately does NOT exclude them: no
     * shared prop carries a contact field today, and if one ever does, this test should say so.
     */
    private const SHARED_PROPS = ['auth', 'nav', 'shift', 'flash', 'errors'];

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(ReferenceSeeder::class);
        $this->seed(AccessControlSeeder::class);
    }

    public function test_a_resident_reaches_the_read_view(): void
    {
        $this->seedYear();

        $resident = User::factory()->create(['position' => 4]);

        $this->actingAs($resident)->get('/rota?year=2026-2027')->assertOk();
    }

    public function test_the_read_view_is_not_under_admin_and_the_editor_still_is(): void
    {
        $this->seedYear();

        $resident = User::factory()->create(['position' => 4]);

        // MR-05 is a reading act; MR-02 is a scheduling one. Two capabilities, two URL spaces
        // (P1d-1 Decision A) — and after P1d-2 Task 1, `rota.manage` is administrator-only.
        $this->actingAs($resident)->get('/rota')->assertOk();
        $this->actingAs($resident)->get('/admin/rota')->assertForbidden();
    }

    /**
     * Decision C's strong form. The institution is set to the MOST permissive contact setting and
     * the viewer is an administrator holding `people.manage`, so BOTH branches of
     * `PersonPolicy::viewContact()` are true — and the props must still carry neither field.
     */
    public function test_no_contact_field_reaches_the_props_for_any_viewer(): void
    {
        [, $person] = $this->seedYear();

        Institution::current()->update(['contact_visibility' => Institution::CONTACT_MEMBERS]);
        $this->assertTrue(
            \App\Support\ContactVisibility::membersMaySeeContact(),
            'the institution fixture is not actually on CONTACT_MEMBERS — this test would prove nothing'
        );

        foreach ([4, 0] as $position) {
            $viewer = User::factory()->create(['position' => $position]);
            $props = $this->propsFrom($this->actingAs($viewer)->get('/rota?year=2026-2027'));

            // The row must actually be there, or "no contact field" is vacuously true.
            $this->assertContains(
                $person->getKey(),
                array_map(fn (array $row): int => $row['person']['id'], $props['grid']['rows']),
                "position {$position} did not receive the seeded person's row at all"
            );

            $this->assertSame([], $this->keyPaths($props, ['email', 'phone']),
                "position {$position} received a contact field in the /rota props");
        }
    }

    /**
     * The half that fails TODAY (finding 3). `/admin/rota` is the surface that already leaks, and
     * it leaks hardest for exactly this viewer.
     */
    public function test_the_editor_grid_is_contact_free_too(): void
    {
        [, $person] = $this->seedYear();

        Institution::current()->update(['contact_visibility' => Institution::CONTACT_MEMBERS]);

        $admin = User::factory()->create(['position' => 0]);
        $props = $this->propsFrom($this->actingAs($admin)->get('/admin/rota?year=2026-2027'));

        $this->assertContains(
            $person->getKey(),
            array_map(fn (array $row): int => $row['person']['id'], $props['grid']['rows']),
        );

        $this->assertSame([], $this->keyPaths($props, ['email', 'phone']),
            'the editor grid shipped a contact field in its Inertia props');
    }

    /**
     * P1c-2 Task 2 / Decision B. AC-02's claim status is `$extra` on `PersonPresenter::one()`,
     * NEVER a base key — and this is the assertion that keeps it that way.
     *
     * `PersonPresenter`'s base map reaches every `rota.view` holder through `contactFree()`, and
     * `rota.view` is seeded for every position (P1d-1 owner decision 2). So a base key is
     * published to the whole department: "who in this department has not claimed their account
     * yet" would become a fact every resident could read out of the props. Adding claim state to
     * the base map is the obvious shortcut when implementing the People screen's status column,
     * it costs one line, and nothing else in the suite would notice.
     *
     * Walked over the WHOLE props tree, and for an ADMINISTRATOR as well as a resident — the same
     * shape as `test_no_contact_field_reaches_the_props_for_any_viewer`, for the same reason: a
     * presenter change must not be able to leak through a row this test did not think to look at.
     */
    public function test_no_invitation_or_claim_state_reaches_the_rota_read_view_for_any_viewer(): void
    {
        [, $person] = $this->seedYear();

        // A real, open invitation for the person on the grid, so "no claim state" is a fact about
        // the projection rather than a fact about an empty invitations table.
        $admin = User::factory()->create(['position' => 0]);
        \App\Models\Invitation::issue('rota.leak@example.test', 4, $admin, $person);

        foreach ([4, 0] as $position) {
            $viewer = $position === 0 ? $admin : User::factory()->create(['position' => $position]);
            $props = $this->propsFrom($this->actingAs($viewer)->get('/rota?year=2026-2027'));

            $this->assertContains(
                $person->getKey(),
                array_map(fn (array $row): int => $row['person']['id'], $props['grid']['rows']),
                "position {$position} did not receive the seeded person's row at all"
            );

            $this->assertSame([], $this->keyPaths($props, ['invitation', 'claim_state', 'invited']),
                "position {$position} received a claim-status key in the /rota props");
        }
    }

    /**
     * Owner decision 1 (2026-08-10): there is no publish gate, so there is nothing anywhere in
     * these props that could carry one. Asserted over the whole page rather than over a key
     * somebody remembered to look at, because the way a draft state arrives is by a later task
     * adding it to a nested prop nobody was watching.
     */
    public function test_there_is_no_publish_state_on_the_read_view(): void
    {
        $this->seedYear();

        $resident = User::factory()->create(['position' => 4]);
        $props = $this->propsFrom($this->actingAs($resident)->get('/rota?year=2026-2027'));

        foreach (self::SHARED_PROPS as $shared) {
            unset($props[$shared]);
        }

        $this->assertSame([], $this->keyPaths($props, ['status', 'published', 'published_at', 'draft']),
            'MR-05 has no publish gate (owner decision 1, 2026-08-10) — the read view always shows '
            .'the current rota');
    }

    /**
     * P1d-2 Task 4, Decision B / P1d-1 pre-merge finding 3: the read view carries its OWN budget,
     * measured on a POPULATED year. A budget measured on an empty grid only ever proves the empty
     * case, and every N+1 `RotaGrid`'s docblock warns about is per-span, per-vacation or
     * per-stale-person — none of which exist on an empty year.
     *
     * TEN stale people, not one: a union written as one query per stale person costs exactly one
     * query when there is exactly one, so a single-stale fixture cannot tell a correct
     * implementation from an N+1.
     *
     * MEASURED: 16 on this exact fixture, read from a first run against a deliberately unreachable
     * bound of 1. The bound below is `assertLessThan`, never an exact count: a first request in a
     * process pays about five queries of session/capability warmup that a second one does not
     * (16, then 11 for the same actor on this fixture), so an exact figure would pin request ORDER
     * rather than grid work. `AvailabilitySummary` adds none of the 16 — it is a pure fold over the
     * array the grid already built (Decision B), which is why the read view costs exactly what
     * `RotaGridTest` measures for the editor. The landing-year lookup adds one query, and only to a
     * request that named no `?year=` — not to this one.
     */
    public function test_the_read_view_renders_a_bounded_number_of_queries(): void
    {
        $this->seedPopulatedYear();

        $resident = User::factory()->create(['position' => 4]);

        DB::enableQueryLog();
        DB::flushQueryLog();

        $this->actingAs($resident)->get('/rota?year=2026-2027')->assertOk();

        $count = count(DB::getQueryLog());

        $this->assertLessThan(20, $count,
            "the read view ran {$count} queries for 780 cells, a summary and a filtered row set");
    }

    /**
     * Decision D's ordering trap, asserted rather than commented. `AvailabilitySummary::forGrid()`
     * runs on the FULL grid and the rows are filtered for display AFTERWARDS; get that backwards
     * and the department's availability figures start depending on what a reader typed into a
     * search box — a summary that quietly reports one person's block as the whole department's.
     */
    public function test_search_and_filter_narrow_the_rows_but_not_the_summary(): void
    {
        [$period] = $this->seedYear();

        // A second, differently-named person on the same period, so `?q=` has something to exclude.
        $other = Person::factory()->create(['full_name' => 'Zzz Unsearched', 'short_name' => 'zzz']);
        LevelAssignment::assign($other, Level::query()->where('code', 'XR1')->firstOrFail(),
            $period->starts_on->format('Y-m-d'));
        RotaAssignment::set($other, $period, Unit::findByCode('XRU'));

        $resident = User::factory()->create(['position' => 4]);

        $all = $this->propsFrom($this->actingAs($resident)->get('/rota?year=2026-2027'));
        $searched = $this->propsFrom($this->actingAs($resident)->get('/rota?year=2026-2027&q=Read+View'));

        $this->assertGreaterThan(count($searched['grid']['rows']), count($all['grid']['rows']),
            'the search returned as many rows as the unfiltered page — it filtered nothing');
        $this->assertSame(['Read View Fixture'],
            array_map(fn (array $row): string => $row['person']['full_name'], $searched['grid']['rows']));

        $this->assertSame($all['summary'], $searched['summary'],
            'the search narrowed the availability summary as well as the row list');
    }

    /**
     * Decision D in both directions at once. A person who has left the department is HIDDEN on the
     * read view — a departed name with a unit beside it reads as current staffing, and it is not —
     * but their occupied cells are NOT silently zeroed either: counting them as coverage overstates
     * availability (the exact failure §35's "match reality" names), and pretending the cells are
     * empty leaves nobody any reason to clear them. So they are their own number.
     */
    public function test_a_deactivated_person_who_still_holds_a_span_is_not_on_the_read_view_but_is_counted(): void
    {
        [$period] = $this->seedYear();

        $departed = Person::factory()->create(['full_name' => 'Omar Departed']);
        LevelAssignment::assign($departed, Level::query()->where('code', 'XR1')->firstOrFail(),
            $period->starts_on->format('Y-m-d'));
        RotaAssignment::set($departed, $period, Unit::findByCode('XRU'));
        $departed->forceFill(['active' => false])->save();

        $resident = User::factory()->create(['position' => 4]);
        $props = $this->propsFrom($this->actingAs($resident)->get('/rota?year=2026-2027'));

        $this->assertNotContains($departed->getKey(),
            array_map(fn (array $row): int => $row['person']['id'], $props['grid']['rows']),
            'a departed person is on the read view, where their unit reads as current staffing');

        // Not reachable by search either — the filter runs over the already-filtered set.
        $searched = $this->propsFrom($this->actingAs($resident)->get('/rota?year=2026-2027&q=Departed'));
        $this->assertSame([], $searched['grid']['rows']);

        $this->assertGreaterThanOrEqual(1, $props['summary'][$period->getKey()]['stale_people'],
            'the departed person\'s occupied cell vanished from the summary as well as the list');
    }

    /**
     * THE LANDING YEAR (P1d-2 Task 4, and this task's one judgment call). The editor lands on
     * "choose a year" because picking the year to plan is the first decision an administrator
     * makes. A reader has no such decision: they came to see where they are now, and an empty
     * screen with a picker asks them to answer a question they did not have. So `/rota` with no
     * `?year=` resolves the year CONTAINING TODAY.
     *
     * Not "the most recent year", which is what a one-line `$years->last()` would give: an
     * administrator generating next year's periods in March (P1b's Periods screen exists to let
     * them) would silently move every resident's landing page onto a mostly-empty future grid
     * while the year they are actually working still had months to run.
     */
    public function test_it_lands_on_the_academic_year_that_contains_today(): void
    {
        $today = CarbonImmutable::today();

        $this->periodIn('2050-2051', $today->subDays(5)->format('Y-m-d'), $today->addDays(5)->format('Y-m-d'));
        // Lexically LATER, so a fallback-to-latest implementation lands here instead and fails.
        $this->periodIn('2099-2100', $today->addYears(40)->format('Y-m-d'), $today->addYears(40)->addDays(20)->format('Y-m-d'));

        $resident = User::factory()->create(['position' => 4]);
        $props = $this->propsFrom($this->actingAs($resident)->get('/rota'));

        $this->assertSame('2050-2051', $props['year']);
        $this->assertNotNull($props['grid'], 'the landing year resolved but no grid was built for it');
    }

    /** No year covers today (a gap between years, or a department planning ahead of itself). */
    public function test_it_falls_back_to_the_most_recent_year_when_today_falls_in_none(): void
    {
        $today = CarbonImmutable::today();

        $this->periodIn('2019-2020', $today->subYears(6)->format('Y-m-d'), $today->subYears(6)->addDays(20)->format('Y-m-d'));
        $this->periodIn('2020-2021', $today->subYears(5)->format('Y-m-d'), $today->subYears(5)->addDays(20)->format('Y-m-d'));

        $resident = User::factory()->create(['position' => 4]);
        $props = $this->propsFrom($this->actingAs($resident)->get('/rota'));

        $this->assertSame('2020-2021', $props['year']);
    }

    /** A department with no periods at all still gets the empty state, not a 500. */
    public function test_a_department_with_no_periods_lands_on_no_year_at_all(): void
    {
        $resident = User::factory()->create(['position' => 4]);
        $props = $this->propsFrom($this->actingAs($resident)->get('/rota'));

        $this->assertNull($props['year']);
        $this->assertNull($props['grid']);
        $this->assertSame([], $props['academic_years']);
    }

    private function periodIn(string $academicYear, string $startsOn, string $endsOn): Period
    {
        return Period::create([
            'academic_year' => $academicYear,
            'kind' => Period::WEEK_BLOCK,
            'position' => 1,
            'label' => 'Block 1',
            'starts_on' => $startsOn,
            'ends_on' => $endsOn,
        ]);
    }

    /**
     * The same populated year `RotaGridTest`'s budget case uses: 60 people, 13 periods, 1170 spans
     * (half of them split, with a deliberate gap), 120 vacations, 30 mid-year promotions and ten
     * stale rows.
     */
    private function seedPopulatedYear(): void
    {
        $level = Level::factory()->create(['code' => 'XV1', 'display_order' => 10]);
        $promoted = Level::factory()->create(['code' => 'XV2', 'display_order' => 20]);
        $unitA = Unit::create(['code' => 'XVA', 'name' => 'Read Budget Unit A', 'active' => true]);
        $unitB = Unit::create(['code' => 'XVB', 'name' => 'Read Budget Unit B', 'active' => true]);

        $periods = [];
        $start = CarbonImmutable::parse('2026-07-01');

        for ($i = 1; $i <= 13; $i++) {
            $periodStart = $start->addWeeks(($i - 1) * 4);

            $periods[] = Period::create([
                'academic_year' => '2026-2027',
                'kind' => Period::WEEK_BLOCK,
                'position' => $i,
                'label' => "Block {$i}",
                'starts_on' => $periodStart->format('Y-m-d'),
                'ends_on' => $periodStart->addWeeks(4)->subDay()->format('Y-m-d'),
            ]);
        }

        for ($index = 0; $index < 60; $index++) {
            $person = Person::factory()->create();
            LevelAssignment::assign($person, $level, $periods[0]->starts_on->format('Y-m-d'));

            foreach ($periods as $period) {
                if ($index % 2 === 0) {
                    RotaAssignment::set($person, $period, $unitA);

                    continue;
                }

                RotaAssignment::split($person, $period, [
                    ['unit_id' => $unitA->getKey(), 'starts_on' => $period->starts_on->format('Y-m-d'),
                        'ends_on' => $period->starts_on->addDays(9)->format('Y-m-d')],
                    ['unit_id' => $unitB->getKey(), 'starts_on' => $period->starts_on->addDays(13)->format('Y-m-d'),
                        'ends_on' => $period->ends_on->format('Y-m-d')],
                ]);
            }

            VacationBooking::book($person,
                $periods[2]->starts_on->addDay()->format('Y-m-d'),
                $periods[2]->starts_on->addDays(3)->format('Y-m-d'),
                Vacation::GRANULARITY_DATE);
            VacationBooking::book($person,
                $periods[8]->starts_on->addDay()->format('Y-m-d'),
                $periods[8]->starts_on->addDays(3)->format('Y-m-d'),
                Vacation::GRANULARITY_DATE);

            if ($index % 2 === 0) {
                LevelAssignment::assign($person, $promoted, $periods[6]->starts_on->format('Y-m-d'));
            }
        }

        for ($i = 0; $i < 10; $i++) {
            $departed = Person::factory()->create();
            LevelAssignment::assign($departed, $level, $periods[0]->starts_on->format('Y-m-d'));
            RotaAssignment::set($departed, $periods[0], $unitA);
            $departed->forceFill(['active' => false])->save();
        }
    }

    /**
     * Every string key anywhere in the tree matching one of $needles, as a dotted path. Walking
     * the whole tree is the point: a future presenter change that leaked through a row this test
     * did not think to look at would still be caught.
     *
     * @param  list<string>  $needles
     * @return list<string>
     */
    private function keyPaths(mixed $node, array $needles, string $path = ''): array
    {
        if (! is_array($node)) {
            return [];
        }

        $found = [];

        foreach ($node as $key => $value) {
            $here = $path === '' ? (string) $key : $path.'.'.$key;

            if (is_string($key) && in_array($key, $needles, true)) {
                $found[] = $here;
            }

            $found = array_merge($found, $this->keyPaths($value, $needles, $here));
        }

        return $found;
    }

    /** @return array<string, mixed> */
    private function propsFrom(\Illuminate\Testing\TestResponse $response): array
    {
        $captured = null;

        $response->assertOk()->assertInertia(function (Assert $page) use (&$captured): void {
            $captured = $page->toArray()['props'];
        });

        $this->assertIsArray($captured);

        return $captured;
    }

    /**
     * One period, one unit, one levelled person holding a whole-period assignment — and that
     * person carries BOTH contact fields, because a fixture with a null phone would make the
     * contact assertions pass for the wrong reason.
     *
     * `XR`-prefixed codes: `Level::factory()->create(['code' => 'R1'])` collides with
     * `ReferenceSeeder`'s seeded ladder (P1c Task 3's recorded trap).
     *
     * @return array{0: Period, 1: Person}
     */
    private function seedYear(): array
    {
        $level = Level::factory()->create(['code' => 'XR1', 'display_order' => 10]);
        $unit = Unit::create(['code' => 'XRU', 'name' => 'Rota Read Unit', 'active' => true]);

        $start = CarbonImmutable::parse('2026-07-01');

        $period = Period::create([
            'academic_year' => '2026-2027',
            'kind' => Period::WEEK_BLOCK,
            'position' => 1,
            'label' => 'Block 1',
            'starts_on' => $start->format('Y-m-d'),
            'ends_on' => $start->addWeeks(4)->subDay()->format('Y-m-d'),
        ]);

        $person = Person::factory()->create([
            'full_name' => 'Read View Fixture',
            'email' => 'fixture@example.test',
            'phone' => '0500000000',
        ]);

        LevelAssignment::assign($person, $level, $start->format('Y-m-d'));
        RotaAssignment::set($person, $period, $unit);

        return [$period, $person];
    }
}
