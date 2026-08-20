<?php

namespace Tests\Feature\Engine;

use App\Models\Clinic;
use App\Models\Holiday;
use App\Models\Institution;
use App\Models\Level;
use App\Models\Period;
use App\Models\Person;
use App\Models\Unit;
use App\Models\Vacation;
use App\Support\Calendar;
use App\Support\Clinics\ClinicWriter;
use App\Support\Engine\ContextBuilder;
use App\Support\LevelAssignment;
use App\Support\Rota\RotaAssignment;
use App\Support\Rota\VacationBooking;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * P2 Task 22 — `App\Support\Engine\ContextBuilder`, the one loader that turns this department's
 * shipped rota, leave, periods, holidays, clinics, levels and units into CG-10's
 * `EvaluationContext`.
 *
 * The guards beside this file assert the two source-level properties (a reader, and no rule in the
 * serialiser). This file asserts the OUTPUT: what the payload contains, what it must never contain,
 * and what it costs.
 */
class ContextBuilderTest extends TestCase
{
    use RefreshDatabase;

    private const FROM = '2026-08-02';

    private const TO = '2026-08-15';

    /**
     * MEASURED, then WATCHED BREACHING (P2 Task 22, 2026-08-20).
     *
     * The populated block below costs THIRTEEN queries, and the count does not move with sixty
     * people, thirteen periods, 1170 spans, sixty leave rows, thirty promotions, four clinics or a
     * multi-year holiday set. The four of headroom is for a legitimate future read and not for an
     * N+1: a bound is only worth having if a real regression clears it, and P1e shipped one of 25
     * against a regression that cost 23. Both regressions this loader exists to prevent were
     * PLANTED against this exact fixture and both were watched red — the numbers are in
     * `ContextBuilder`'s own docblock beside the lines that avoid them.
     */
    private const QUERY_BUDGET = 17;

    protected function setUp(): void
    {
        parent::setUp();

        Institution::query()->create([
            'code' => 'QCH',
            'name' => 'Test Institution',
            'active' => true,
            'weekend_days' => [5, 6],
        ]);

        Calendar::flush();
    }

    public function test_the_day_vector_covers_every_date_of_the_horizon(): void
    {
        $this->seedPeriod();

        $context = ContextBuilder::forHorizon(self::FROM, self::TO);

        $this->assertSame(
            Calendar::datesBetween(self::FROM, self::TO),
            array_column($context['days'], 'date'),
            'the day vector must cover the whole horizon, one entry per date'
        );

        $first = $context['days'][0];

        $this->assertSame(7, $first['isoWeekday'], '2026-08-02 is a Sunday');
        $this->assertSame(Calendar::DAY_WEEKDAY, $first['dayType']);
        $this->assertSame([], $first['holidays']);
        $this->assertNotNull($first['periodKey']);
    }

    public function test_the_day_type_is_the_calendars_answer_and_holiday_beats_weekend(): void
    {
        $this->seedPeriod();

        // 2026-08-07 is a Friday — a weekend day for this department. A holiday anchored there
        // must still report HOL: `Calendar::dayType()` makes holiday win over weekend on purpose,
        // and a context that re-derived the answer from `weekendDays` alone would disagree on
        // exactly the days it matters.
        Holiday::query()->create([
            'name' => 'A Test Day',
            'calendar' => Holiday::GREGORIAN,
            'month' => 8,
            'day' => 7,
            'duration_days' => 1,
            'active' => true,
        ]);

        Calendar::flush();

        $context = ContextBuilder::forHorizon(self::FROM, self::TO);
        $byDate = array_column($context['days'], null, 'date');

        $this->assertSame(Calendar::DAY_HOLIDAY, $byDate['2026-08-07']['dayType']);
        $this->assertSame(Calendar::DAY_WEEKEND, $byDate['2026-08-08']['dayType']);
        $this->assertCount(1, $byDate['2026-08-07']['holidays']);
        $this->assertSame(2026, $byDate['2026-08-07']['holidays'][0]['year']);
    }

    /**
     * Owner decision W: the year carried is the holiday's OWN-CALENDAR year, and it is the year of
     * the ANCHOR rather than of the date being asked about. A span that runs across a year end is
     * the case where the two answers differ, and it is the case `holiday_equity` keys credits on.
     */
    public function test_a_multi_day_holiday_carries_the_year_of_its_anchor_not_of_the_date(): void
    {
        Period::query()->create([
            'academic_year' => '2026-2027',
            'kind' => Period::WEEK_BLOCK,
            'position' => 7,
            'label' => 'Block 7',
            'starts_on' => '2026-12-28',
            'ends_on' => '2027-01-10',
        ]);

        Holiday::query()->create([
            'name' => 'A Test Span',
            'calendar' => Holiday::GREGORIAN,
            'month' => 12,
            'day' => 30,
            'duration_days' => 4,
            'active' => true,
        ]);

        Calendar::flush();

        $context = ContextBuilder::forHorizon('2026-12-28', '2027-01-05');
        $byDate = array_column($context['days'], null, 'date');

        $this->assertSame([], $byDate['2026-12-29']['holidays']);
        $this->assertSame(2026, $byDate['2026-12-30']['holidays'][0]['year']);
        $this->assertSame(
            2026,
            $byDate['2027-01-02']['holidays'][0]['year'],
            'the fourth day of a span anchored in 2026 belongs to the 2026 occurrence, not to 2027'
        );
        $this->assertSame([], $byDate['2027-01-03']['holidays']);
    }

    public function test_the_periods_carry_the_departments_own_clipped_week_bounds(): void
    {
        $period = $this->seedPeriod();

        $context = ContextBuilder::forHorizon(self::FROM, self::TO);

        $this->assertCount(1, $context['periods']);
        $this->assertSame($period->starts_on->format(Calendar::YMD), $context['periods'][0]['startsOn']);

        $weeks = $context['periods'][0]['weeks'];

        $this->assertSame(
            array_map(fn (array $week): array => [
                $week['starts_on'], $week['ends_on'], $week['clipped_starts_on'], $week['clipped_ends_on'],
            ], Calendar::weeksIn($period->starts_on, $period->ends_on)),
            array_map(fn (array $week): array => [
                $week['startsOn'], $week['endsOn'], $week['clippedStartsOn'], $week['clippedEndsOn'],
            ], $weeks),
            'the week windows are the one converter\'s, carried rather than recomputed'
        );

        // The clipped bounds are the load-bearing half: this period begins on a Sunday, which is
        // this department's own week start, so it is the SECOND week that a naive copy would get
        // wrong. `golden.json` has zero coverage of them, which is why they are carried and never
        // mirrored (owner decision O).
        $this->assertSame('2026-08-09', $weeks[1]['startsOn']);
        $this->assertSame('2026-08-15', $weeks[1]['clippedEndsOn']);
    }

    public function test_a_person_carries_their_unit_spans_level_spans_and_leave_days(): void
    {
        $period = $this->seedPeriod();
        $unit = Unit::query()->create(['code' => 'XEA', 'name' => 'Engine Unit A', 'active' => true]);
        $level = Level::factory()->create(['code' => 'XE1', 'display_order' => 10]);

        $person = Person::factory()->create();
        LevelAssignment::assign($person, $level, '2026-01-01');
        RotaAssignment::set($person, $period, $unit);
        VacationBooking::book($person, '2026-08-05', '2026-08-07', Vacation::GRANULARITY_DATE);

        $context = ContextBuilder::forHorizon(self::FROM, self::TO);
        $entry = $this->personEntry($context, $person);

        $this->assertSame([['key' => 'XEA', 'from' => '2026-08-02', 'to' => '2026-08-15']], $entry['unitSpans']);
        $this->assertSame('XE1', $entry['levelSpans'][0]['key']);
        $this->assertSame('2026-01-01', $entry['levelSpans'][0]['from']);
        $this->assertSame(['2026-08-05', '2026-08-06', '2026-08-07'], $entry['leaveDays']);
        $this->assertFalse($entry['external']);
        $this->assertArrayNotHasKey('joinedAt', $entry, 'a join date nobody typed is ABSENT, never null');
    }

    /**
     * `spanKeyAt` reads a date outside every span as "holds nothing", which is a real state. A
     * level span with no end has no end, and clipping it to the horizon would tell the engine that
     * a person holds no level on the day after the last horizon date — where `clinic_conflict`'s
     * post-duty window legitimately looks.
     */
    public function test_an_open_ended_level_span_is_not_clipped_to_the_horizon(): void
    {
        $this->seedPeriod();
        $level = Level::factory()->create(['code' => 'XE2', 'display_order' => 20]);
        $person = Person::factory()->create();
        LevelAssignment::assign($person, $level, '2026-01-01');

        $entry = $this->personEntry(ContextBuilder::forHorizon(self::FROM, self::TO), $person);

        $this->assertSame(ContextBuilder::NO_KNOWN_END, $entry['levelSpans'][0]['to']);
        $this->assertGreaterThan(self::TO, $entry['levelSpans'][0]['to']);
    }

    /**
     * Owner decision J, answered: the denominator is ELIGIBLE DAYS. Leave and the dates before a
     * join date come out; nothing else is inferred, because nothing else is recorded.
     */
    public function test_eligible_days_remove_leave_and_the_dates_before_a_join_date(): void
    {
        $this->seedPeriod();
        $person = Person::factory()->create(['joined_at' => '2026-08-05']);
        VacationBooking::book($person, '2026-08-10', '2026-08-11', Vacation::GRANULARITY_DATE);

        $entry = $this->personEntry(ContextBuilder::forHorizon(self::FROM, self::TO), $person);

        $this->assertSame('2026-08-05', $entry['joinedAt']);
        $this->assertNotContains('2026-08-04', $entry['eligibleDays']);
        $this->assertContains('2026-08-05', $entry['eligibleDays']);
        $this->assertNotContains('2026-08-10', $entry['eligibleDays']);
        $this->assertNotContains('2026-08-11', $entry['eligibleDays']);
        $this->assertContains('2026-08-12', $entry['eligibleDays']);
        $this->assertCount(9, $entry['eligibleDays']);
    }

    /**
     * A duty naming a person the context does not describe THROWS in the engine, so a departed
     * colleague who still holds a span must be described. `RotaGrid`'s stale-row union, one layer
     * along.
     */
    public function test_a_departed_person_still_holding_a_span_is_carried(): void
    {
        $period = $this->seedPeriod();
        $unit = Unit::query()->create(['code' => 'XEB', 'name' => 'Engine Unit B', 'active' => true]);

        $departed = Person::factory()->create();
        RotaAssignment::set($departed, $period, $unit);
        $departed->forceFill(['active' => false])->save();

        $entry = $this->personEntry(ContextBuilder::forHorizon(self::FROM, self::TO), $departed);

        $this->assertSame([['key' => 'XEB', 'from' => '2026-08-02', 'to' => '2026-08-15']], $entry['unitSpans']);
    }

    public function test_clinics_carry_their_mode_and_their_attendee_rows(): void
    {
        $this->seedPeriod();
        $unit = Unit::query()->create(['code' => 'XEC', 'name' => 'Engine Unit C', 'active' => true, 'clinic_owner' => true]);
        $level = Level::factory()->create(['code' => 'XE3', 'display_order' => 30]);

        $clinic = ClinicWriter::create($unit, ['name' => 'Tuesday Clinic', 'weekday' => 2, 'session' => 'AM']);
        ClinicWriter::setAttendees($clinic, Clinic::MODE_LEVELS, [['level_id' => $level->getKey()]]);

        $context = ContextBuilder::forHorizon(self::FROM, self::TO);

        $this->assertCount(1, $context['clinics']);
        $this->assertSame('XEC', $context['clinics'][0]['unitKey']);
        $this->assertSame(2, $context['clinics'][0]['isoWeekday']);
        $this->assertSame('AM', $context['clinics'][0]['session']);
        $this->assertTrue($context['clinics'][0]['active']);
        $this->assertSame(Clinic::MODE_LEVELS, $context['clinics'][0]['attendeeMode']);
        $this->assertSame(['XE3'], $context['clinics'][0]['attendeeLevelKeys']);
        $this->assertSame([], $context['clinics'][0]['attendeePersonKeys']);
    }

    /**
     * A deactivated clinic RESOLVES — resolution is not authorization (`ClinicRoster`'s rule) —
     * and, more to the point here, deciding that an inactive clinic cannot produce a finding is
     * the condition's call, not the loader's.
     */
    public function test_a_deactivated_clinic_is_carried_with_its_flag_rather_than_filtered_out(): void
    {
        $this->seedPeriod();
        $unit = Unit::query()->create(['code' => 'XED', 'name' => 'Engine Unit D', 'active' => true, 'clinic_owner' => true]);

        $clinic = ClinicWriter::create($unit, ['name' => 'Retired Clinic', 'weekday' => 3, 'session' => 'PM']);
        ClinicWriter::setActive($clinic, false);

        $context = ContextBuilder::forHorizon(self::FROM, self::TO);

        $this->assertCount(1, $context['clinics']);
        $this->assertFalse($context['clinics'][0]['active']);
    }

    public function test_the_caller_supplies_the_halves_this_schema_does_not_record(): void
    {
        $this->seedPeriod();
        $person = Person::factory()->create();
        $key = ContextBuilder::personKey($person);

        $slot = [
            'key' => 'night', 'kind' => 'call', 'cadence' => 'daily', 'spanDays' => 1,
            'startMinute' => 1020, 'endMinute' => 480, 'crossesMidnight' => true,
            'countsHours' => true,
        ];

        $context = ContextBuilder::forHorizon(
            self::FROM,
            self::TO,
            slots: [$slot],
            priorDuties: [['personKey' => $key, 'date' => '2026-08-01', 'slotKey' => 'night']],
            historyAvailableFrom: '2026-07-01',
            unwantedDays: [$key => ['2026-08-09']],
        );

        $this->assertSame([$slot], $context['slots']);
        $this->assertSame('2026-07-01', $context['historyAvailableFrom']);
        $this->assertCount(1, $context['priorDuties']);
        $this->assertSame([], $context['followingDuties']);
        $this->assertSame(['2026-08-09'], $this->personEntry($context, $person)['unwantedDays']);
    }

    public function test_with_nothing_supplied_the_caller_halves_are_empty_and_history_is_null(): void
    {
        $this->seedPeriod();
        Person::factory()->create();

        $context = ContextBuilder::forHorizon(self::FROM, self::TO);

        $this->assertSame([], $context['slots']);
        $this->assertSame([], $context['priorDuties']);
        $this->assertSame([], $context['followingDuties']);
        $this->assertNull($context['historyAvailableFrom']);
        $this->assertSame(Calendar::timezone(), $context['timezone']);
        $this->assertSame(Calendar::weekendDays(), $context['weekendDays']);
        $this->assertSame(Calendar::weekStartIsoDay(), $context['weekStartIsoDay']);
        $this->assertSame(Calendar::todayYmd(), $context['today']);
    }

    /**
     * INVARIANT 13, ASSERTED OVER THE PAYLOAD RATHER THAN OVER THE CODE.
     *
     * P1d-2 shipped every colleague's address and mobile number in the rota grid's Inertia props
     * with nothing rendering them, on a DEFAULT department. This context is built for a payload
     * another person's browser holds (WB-03), it takes no viewer at all, and it is asserted on the
     * most permissive institution setting the system can produce.
     */
    public function test_no_contact_field_can_reach_the_payload(): void
    {
        $period = $this->seedPeriod();
        $unit = Unit::query()->create(['code' => 'XEE', 'name' => 'Engine Unit E', 'active' => true]);
        $level = Level::factory()->create(['code' => 'XE4', 'display_order' => 40]);

        Institution::current()->update(['contact_visibility' => Institution::CONTACT_MEMBERS]);

        $person = Person::factory()->create([
            'phone' => '+966500000000',
            'notes' => 'a supervisor wrote this',
            'constraints' => ['no nights'],
        ]);
        LevelAssignment::assign($person, $level, '2026-01-01');
        RotaAssignment::set($person, $period, $unit);

        $context = ContextBuilder::forHorizon(self::FROM, self::TO);

        $this->assertNotSame([], $context['people'], 'the scan below proves nothing over an empty roster');

        $this->assertSame(
            [],
            $this->keyPaths($context, ['email', 'phone', 'notes', 'constraints', 'full_name', 'fullName', 'short_name', 'shortName', 'name']),
            'the engine context shipped a contact field or a name'
        );

        $encoded = (string) json_encode($context);

        foreach (['+966500000000', 'a supervisor wrote this', 'no nights', (string) $person->full_name] as $secret) {
            $this->assertStringNotContainsString($secret, $encoded, 'a person\'s own text reached the engine payload');
        }
    }

    /**
     * THE QUERY BUDGET, MEASURED ON A POPULATED HORIZON AND WATCHED BREACHING.
     *
     * A budget measured on an empty context only ever proves the empty case (`RotaGridTest`'s
     * recorded reason). This one is measured over a whole academic year of periods, sixty people,
     * splits, mid-year promotions, leave, departed span-holders, four clinics and a multi-year
     * holiday set — and the count does not move with any of them.
     *
     * WATCHED BREACHING (P2 Task 22, 2026-08-20): with each level span's key resolved by
     * `Person::find(…)->levelAt(…)` instead of `levelSpansBetween()` fetched once, this ran **223**
     * queries against the bound of 17. With `$assignment->unit` read per span instead of the
     * id-keyed map, **113**. Both were planted, both were seen red, both were reverted.
     *
     * A budget cannot see the third trap in `ContextBuilder`'s list, and that is worth knowing
     * rather than assuming: a narrowed `select()` on the person query is CHEAPER, not dearer. It
     * was planted too, and what caught it was
     * `test_eligible_days_remove_leave_and_the_dates_before_a_join_date` going red on a join date
     * that had silently become absent for everybody.
     */
    public function test_the_context_is_a_bounded_number_of_queries(): void
    {
        [$periods, $people, $unitA, $level] = $this->seedPopulatedYear();

        DB::enableQueryLog();
        DB::flushQueryLog();

        $context = ContextBuilder::forHorizon(
            $periods[0]->starts_on->format(Calendar::YMD),
            $periods[0]->ends_on->format(Calendar::YMD),
        );

        $count = count(DB::getQueryLog());
        DB::disableQueryLog();

        $this->assertGreaterThanOrEqual(60, count($context['people']), 'the budget was measured over an empty roster');

        $this->assertLessThan(
            self::QUERY_BUDGET,
            $count,
            "the context ran {$count} queries for a populated block"
        );
    }

    /**
     * The other half of the budget, and the half a bound alone cannot state: the count is the same
     * whether the horizon holds one person or sixty. A bound that grows with the roster passes
     * until the department does.
     */
    public function test_the_query_count_does_not_grow_with_the_roster(): void
    {
        $period = $this->seedPeriod();
        $unit = Unit::query()->create(['code' => 'XEF', 'name' => 'Engine Unit F', 'active' => true]);
        $level = Level::factory()->create(['code' => 'XE5', 'display_order' => 50]);

        $person = Person::factory()->create();
        LevelAssignment::assign($person, $level, '2026-01-01');
        RotaAssignment::set($person, $period, $unit);

        $one = $this->countQueries(fn () => ContextBuilder::forHorizon(self::FROM, self::TO));

        for ($i = 0; $i < 30; $i++) {
            $extra = Person::factory()->create();
            LevelAssignment::assign($extra, $level, '2026-01-01');
            RotaAssignment::set($extra, $period, $unit);
            VacationBooking::book($extra, '2026-08-04', '2026-08-06', Vacation::GRANULARITY_DATE);
        }

        $many = $this->countQueries(fn () => ContextBuilder::forHorizon(self::FROM, self::TO));

        $this->assertSame($one, $many, "one person cost {$one} queries and thirty-one cost {$many}");
    }

    /**
     * `Calendar::settings()` and `Calendar::activeHolidays()` are memoized per PROCESS, so a
     * second call inside one test costs two queries fewer than the first for a reason that has
     * nothing to do with the roster. Both measurements are taken COLD, or the comparison below
     * would report a saving the warm cache made.
     */
    private function countQueries(callable $callback): int
    {
        Calendar::flush();
        DB::enableQueryLog();
        DB::flushQueryLog();

        $callback();

        $count = count(DB::getQueryLog());
        DB::disableQueryLog();

        return $count;
    }

    /** @return array<string, mixed> */
    private function personEntry(array $context, Person $person): array
    {
        $key = ContextBuilder::personKey($person);
        $entries = array_column($context['people'], null, 'key');

        $this->assertArrayHasKey($key, $entries, "the context does not describe {$key}");

        return $entries[$key];
    }

    private function seedPeriod(): Period
    {
        return Period::query()->create([
            'academic_year' => '2026-2027',
            'kind' => Period::WEEK_BLOCK,
            'position' => 1,
            'label' => 'Block 1',
            'starts_on' => self::FROM,
            'ends_on' => self::TO,
        ]);
    }

    /**
     * A whole academic year, populated the way `RotaGridTest` populates its own: splits, gaps,
     * leave, mid-year promotions and departed span-holders, plus the clinics and holidays this
     * context reads and that grid does not.
     *
     * @return array{0: list<Period>, 1: list<Person>, 2: Unit, 3: Level}
     */
    private function seedPopulatedYear(): array
    {
        $level = Level::factory()->create(['code' => 'XEY1', 'display_order' => 10]);
        $promoted = Level::factory()->create(['code' => 'XEY2', 'display_order' => 20]);
        $unitA = Unit::query()->create(['code' => 'XEY', 'name' => 'Engine Year Unit', 'active' => true, 'clinic_owner' => true]);
        $unitB = Unit::query()->create(['code' => 'XEZ', 'name' => 'Engine Year Unit Z', 'active' => true]);

        $periods = [];
        $start = CarbonImmutable::parse('2026-07-01');

        for ($i = 1; $i <= 13; $i++) {
            $periodStart = $start->addWeeks(($i - 1) * 4);

            $periods[] = Period::query()->create([
                'academic_year' => '2026-2027',
                'kind' => Period::WEEK_BLOCK,
                'position' => $i,
                'label' => "Block {$i}",
                'starts_on' => $periodStart->format(Calendar::YMD),
                'ends_on' => $periodStart->addWeeks(4)->subDay()->format(Calendar::YMD),
            ]);
        }

        $people = [];

        for ($index = 0; $index < 60; $index++) {
            $person = Person::factory()->create(['joined_at' => '2026-06-01']);
            LevelAssignment::assign($person, $level, $periods[0]->starts_on->format(Calendar::YMD));
            $people[] = $person;

            foreach ($periods as $period) {
                $from = $period->starts_on->format(Calendar::YMD);
                $to = $period->ends_on->format(Calendar::YMD);

                if ($index % 2 === 0) {
                    RotaAssignment::set($person, $period, $unitA);

                    continue;
                }

                RotaAssignment::split($person, $period, [
                    ['unit_id' => $unitA->getKey(), 'starts_on' => $from, 'ends_on' => $period->starts_on->addDays(9)->format(Calendar::YMD)],
                    ['unit_id' => $unitB->getKey(), 'starts_on' => $period->starts_on->addDays(13)->format(Calendar::YMD), 'ends_on' => $to],
                ]);
            }

            VacationBooking::book(
                $person,
                $periods[0]->starts_on->addDay()->format(Calendar::YMD),
                $periods[0]->starts_on->addDays(3)->format(Calendar::YMD),
                Vacation::GRANULARITY_DATE,
            );

            if ($index % 2 === 0) {
                LevelAssignment::assign($person, $promoted, $periods[6]->starts_on->format(Calendar::YMD));
            }
        }

        for ($i = 0; $i < 10; $i++) {
            $departed = Person::factory()->create();
            LevelAssignment::assign($departed, $level, $periods[0]->starts_on->format(Calendar::YMD));
            RotaAssignment::set($departed, $periods[0], $unitA);
            $departed->forceFill(['active' => false])->save();
        }

        foreach ([1, 2, 3, 4] as $weekday) {
            $clinic = ClinicWriter::create($unitA, [
                'name' => "Clinic {$weekday}",
                'weekday' => $weekday,
                'session' => $weekday % 2 === 0 ? 'PM' : 'AM',
            ]);
            ClinicWriter::setAttendees($clinic, Clinic::MODE_LEVELS, [['level_id' => $level->getKey()]]);
        }

        foreach ([[1, 1], [7, 4], [9, 23], [12, 25]] as [$month, $day]) {
            Holiday::query()->create([
                'name' => "Holiday {$month}-{$day}",
                'calendar' => Holiday::GREGORIAN,
                'month' => $month,
                'day' => $day,
                'duration_days' => 2,
                'active' => true,
            ]);
        }

        Calendar::flush();

        return [$periods, $people, $unitA, $level];
    }

    /**
     * Every key path in a nested array whose KEY is one of `$needles` — `RotaReadViewTest`'s own
     * scan, because a field that is absent from the props is the only kind that cannot be rendered
     * by accident.
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
}
