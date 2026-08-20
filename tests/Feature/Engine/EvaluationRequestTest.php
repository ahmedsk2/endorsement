<?php

namespace Tests\Feature\Engine;

use App\Models\Institution;
use App\Models\Period;
use App\Models\Person;
use App\Models\Unit;
use App\Models\Vacation;
use App\Support\Calendar;
use App\Support\Engine\ContextBuilder;
use App\Support\Engine\EvaluationRequest;
use App\Support\Rota\RotaAssignment;
use App\Support\Rota\VacationBooking;
use Illuminate\Foundation\Testing\RefreshDatabase;
use InvalidArgumentException;
use Tests\TestCase;

/**
 * P2 Task 24 — `App\Support\Engine\EvaluationRequest`, and the ONE defect it exists to close.
 *
 * ## THE DEFECT (P2 Task 21, finding 6)
 *
 * `ContextBuilder::forHorizon()` builds `days` and `eligibleDays` over the single range it is
 * given. A caller that hands it the PERIOD and then widens `horizon.evaluableFrom` to reach the
 * carry-in tail has told the engine two different things: *"these are the dates I can answer for"*
 * and *"here is availability for a shorter stretch"*. Absence of data and unavailability are
 * indistinguishable in a list of dates, so `call_frequency_max` reads a 28-day window overlapping
 * the tail as *"this person was available on one of these days"*, permits `floor(1 / 3) = 0` calls,
 * and fires on a schedule that breaches nothing.
 *
 * Task 24 is the first caller and owes the fix. It is closed STRUCTURALLY rather than by
 * discipline: the horizon's evaluable bounds are READ OFF the day vector that was actually built,
 * so a widening past what the context describes cannot be expressed at all. The two assertions
 * below are the two halves of that, and the second is the one that would fire.
 *
 * ## WHY BOTH HALVES
 *
 * The structural half (`evaluableFrom === days[0].date`) is green on a builder that returned an
 * empty vector, and the behavioural half (availability reaches the tail) is green on a caller that
 * happened to pass matching bounds this once. Neither is sufficient — Task 22's finding 2, one
 * file along.
 *
 * The third half runs the REAL engine on both readings of one world and watches the wrong one fire
 * on a clean month; it needs Node and the compiled bundle, so it lives in
 * {@see EngineEvaluateCommandTest} beside the command that pipes them, and the same case is
 * fixtured in the package corpus (`call-frequency-max-availability-*`) where it runs with no PHP at
 * all.
 */
class EvaluationRequestTest extends TestCase
{
    use RefreshDatabase;

    /** The period being drafted. */
    private const FROM = '2026-08-02';

    private const TO = '2026-08-15';

    /**
     * The earliest already-published duty the caller supplies, thirteen days before the period.
     *
     * Chosen so that a 28-day density window anchored here reaches back past `FROM` and still ends
     * inside the period: that is the exact window the wrong reading measures against fourteen days
     * of availability instead of twenty-seven.
     */
    private const TAIL_DUTY_DATE = '2026-07-20';

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

    /**
     * THE STRUCTURAL HALF. The horizon cannot claim a range the context does not describe, because
     * it does not carry its own copy of one — both evaluable bounds are read off the day vector.
     */
    public function test_the_evaluable_bounds_are_read_off_the_day_vector_that_was_built(): void
    {
        $period = $this->seedPeriod();
        Person::factory()->create();

        $request = EvaluationRequest::forPeriod($period, $this->document());

        $days = $request['context']['days'];
        $horizon = $request['schedule']['horizon'];

        $this->assertSame(self::FROM, $horizon['from']);
        $this->assertSame(self::TO, $horizon['to']);
        $this->assertSame($days[0]['date'], $horizon['evaluableFrom']);
        $this->assertSame($days[array_key_last($days)]['date'], $horizon['evaluableTo']);

        // And the vector really is the widened one, not the period: a check that passed because
        // both were the period would prove nothing at all.
        $this->assertSame(self::TAIL_DUTY_DATE, $horizon['evaluableFrom']);
        $this->assertCount(27, $days);
    }

    /**
     * THE BEHAVIOURAL HALF, AND THE ONE THAT FIRES UNDER THE WRONG READING.
     *
     * Availability must be a fact about the whole evaluable range. Built over the period alone, the
     * dates in the tail are simply absent from `eligibleDays` — which reads, on the far side of the
     * contract, as *"this person could not have been scheduled then"*.
     */
    public function test_availability_covers_the_whole_evaluable_range_and_not_only_the_period(): void
    {
        $period = $this->seedPeriod();
        $person = Person::factory()->create(['joined_at' => null]);

        $request = EvaluationRequest::forPeriod($period, $this->document());
        $entry = $this->personEntry($request['context'], $person);

        $this->assertContains(self::TAIL_DUTY_DATE, $entry['eligibleDays']);
        $this->assertContains(self::FROM, $entry['eligibleDays']);
        $this->assertSame(
            $request['schedule']['horizon']['evaluableFrom'],
            $entry['eligibleDays'][0],
            'availability starts later than the range the horizon says is evaluable'
        );
        $this->assertCount(27, $entry['eligibleDays']);
    }

    /**
     * Widening does not make the department's real facts up: leave and a join date inside the tail
     * still come out, exactly as they do inside the period. The widened range is a bigger question
     * asked of the same tables, not a licence to assume availability.
     */
    public function test_leave_inside_the_tail_still_leaves_the_denominator(): void
    {
        $period = $this->seedPeriod();
        $person = Person::factory()->create(['joined_at' => null]);
        VacationBooking::book($person, '2026-07-22', '2026-07-23', Vacation::GRANULARITY_DATE);

        $entry = $this->personEntry(EvaluationRequest::forPeriod($period, $this->document())['context'], $person);

        $this->assertContains('2026-07-21', $entry['eligibleDays']);
        $this->assertNotContains('2026-07-22', $entry['eligibleDays']);
        $this->assertNotContains('2026-07-23', $entry['eligibleDays']);
        $this->assertContains('2026-07-24', $entry['eligibleDays']);
    }

    /**
     * A duty whose slot spans several dates pushes the right edge to its LAST date, not its anchor.
     * Duty end is `date + spanDays - 1`; a weekly slot starting on the final date of the period
     * occupies six dates the context would otherwise not describe.
     */
    public function test_the_right_edge_reaches_the_last_date_a_duty_occupies(): void
    {
        $period = $this->seedPeriod();
        Person::factory()->create();

        $document = $this->document();
        $document['slots'][] = [
            'key' => 'ward-week',
            'kind' => 'ward',
            'cadence' => 'weekly',
            'spanDays' => 7,
            'startMinute' => 480,
            'endMinute' => 1020,
            'crossesMidnight' => false,
            'countsHours' => true,
        ];
        $document['duties'][] = ['personKey' => 'p1', 'date' => self::TO, 'slotKey' => 'ward-week'];

        $request = EvaluationRequest::forPeriod($period, $document);
        $days = $request['context']['days'];
        $horizon = $request['schedule']['horizon'];

        $this->assertSame('2026-08-21', $horizon['evaluableTo']);
        $this->assertSame(self::TO, $horizon['to'], 'the period itself must not move');

        // The day vector has to REACH it. Without this line the case asserts the widening
        // arithmetic and not the binding, and it stayed GREEN under the plant that broke the
        // binding on the left edge — the right edge happened to agree because nothing in the base
        // document reaches past the period.
        $this->assertSame('2026-08-21', $days[array_key_last($days)]['date']);
    }

    /**
     * The engine throws on a duty naming a slot it was not given, and the entrypoint reports that
     * as a BUG (exit 1) because it is not a contract failure. Refusing here turns a stack trace
     * into a sentence naming the slot, before anything is spawned.
     */
    public function test_a_duty_naming_an_unsupplied_slot_is_refused_by_name(): void
    {
        $period = $this->seedPeriod();

        $document = $this->document();
        $document['duties'][] = ['personKey' => 'p1', 'date' => self::FROM, 'slotKey' => 'ghost'];

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/ghost/');

        EvaluationRequest::forPeriod($period, $document);
    }

    /** A document whose halves are the wrong KIND of value is refused before the payload is built. */
    public function test_a_document_whose_duties_are_not_a_list_is_refused(): void
    {
        $period = $this->seedPeriod();

        $document = $this->document();
        $document['duties'] = 'every night';

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/duties/');

        EvaluationRequest::forPeriod($period, $document);
    }

    /**
     * The caller's own halves reach the context unchanged — this class assembles, it does not
     * interpret. `historyAvailableFrom` in particular is the caller's claim about how far back it
     * looked, and narrowing it to the widened range would quietly answer a question nobody asked.
     */
    public function test_the_callers_own_halves_travel_through_untouched(): void
    {
        $period = $this->seedPeriod();
        Person::factory()->create();

        $request = EvaluationRequest::forPeriod($period, $this->document());

        $this->assertSame('2026-07-01', $request['context']['historyAvailableFrom']);
        $this->assertCount(1, $request['context']['priorDuties']);
        $this->assertSame([], $request['context']['followingDuties']);
        $this->assertSame('night', $request['context']['slots'][0]['key']);
        $this->assertSame('c-density', $request['conditions'][0]['id']);
    }

    /**
     * The whole point of a widened range is that it costs a bigger read, not a different one. The
     * loader's bound is Task 22's and this caller does not get its own.
     */
    public function test_the_widened_range_is_still_one_bounded_read(): void
    {
        $period = $this->seedPeriod();
        $unit = Unit::query()->create(['code' => 'XER', 'name' => 'Engine Request Unit', 'active' => true]);

        foreach (range(1, 8) as $ignored) {
            $person = Person::factory()->create();
            RotaAssignment::set($person, $period, $unit);
        }

        $queries = 0;
        \Illuminate\Support\Facades\DB::listen(function () use (&$queries): void {
            $queries++;
        });

        EvaluationRequest::forPeriod($period, $this->document());

        $this->assertLessThanOrEqual(
            17,
            $queries,
            'the request assembler grew a read of its own; the loader is the one bounded read'
        );
    }

    /**
     * The caller-supplied duty document, in the shape the command reads from a file.
     *
     * Deliberately synthetic and deliberately CLEAN: one already-published call in the tail and one
     * inside the period, against a one-in-three density averaged over four weeks. Twenty-seven
     * available days permit nine calls. Fourteen — the wrong reading — permit four, which this
     * world still clears; what it does NOT clear is the window anchored on 2026-07-20, where the
     * wrong reading sees a single available day and permits none. That is the case
     * {@see EngineEvaluateCommandTest} runs through the real engine.
     *
     * @return array<string, mixed>
     */
    private function document(): array
    {
        return [
            'slots' => [[
                'key' => 'night',
                'kind' => 'call',
                'cadence' => 'daily',
                'spanDays' => 1,
                'startMinute' => 1200,
                'endMinute' => 480,
                'crossesMidnight' => true,
                'countsHours' => true,
                'tallyKey' => 'nights',
            ]],
            'duties' => [
                ['personKey' => 'p1', 'date' => '2026-08-06', 'slotKey' => 'night'],
            ],
            'priorDuties' => [
                ['personKey' => 'p1', 'date' => self::TAIL_DUTY_DATE, 'slotKey' => 'night'],
            ],
            'followingDuties' => [],
            'historyAvailableFrom' => '2026-07-01',
            'unwantedDays' => [],
            'conditions' => [[
                'id' => 'c-density',
                'typeKey' => 'call_frequency_max',
                'class' => 'soft',
                'rank' => 1,
                'active' => true,
                'params' => ['n' => 3, 'windowDays' => 28],
            ]],
        ];
    }

    /**
     * @param  array<string, mixed>  $context
     * @return array<string, mixed>
     */
    private function personEntry(array $context, Person $person): array
    {
        $entries = array_column($context['people'], null, 'key');
        $key = ContextBuilder::personKey($person);

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
}
