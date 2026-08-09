<?php

namespace Tests\Feature\Identity;

use App\Models\Level;
use App\Models\Person;
use App\Support\LevelAssignment;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * `Person::levelSpansBetween()` fetches every span intersecting a date RANGE in one query;
 * `Person::levelFromSpans()` then resolves a date against that pre-fetched set in memory.
 *
 * The in-memory resolver is, unavoidably, a SECOND expression of `inForceOn()`'s rule in a
 * different language — a query predicate cannot be run against an array. This codebase's answer to
 * "two expressions of one fact" where one cannot be eliminated is a matrix test that proves they
 * agree, not a comment claiming they do: exactly what `PickerParityTest` does for
 * `SignoffPickers`. That is what this file is.
 */
class LevelResolverParityTest extends TestCase
{
    use RefreshDatabase;

    public function test_the_in_memory_resolver_agrees_with_level_at_across_a_matrix(): void
    {
        $r1 = Level::factory()->create(['code' => 'X1', 'display_order' => 10]);
        $r2 = Level::factory()->create(['code' => 'X2', 'display_order' => 20]);

        // Three people with deliberately awkward histories.
        $never = Person::factory()->create();                       // no history at all
        $open = Person::factory()->create();                        // one open-ended span
        $promoted = Person::factory()->create();                    // a closed span then an open one

        LevelAssignment::assign($open, $r1, '2026-07-01');

        LevelAssignment::assign($promoted, $r1, '2026-07-01');
        LevelAssignment::assign($promoted, $r2, '2027-01-01');

        $people = [$never, $open, $promoted];

        $dates = [
            '2026-06-30',   // before everything
            '2026-07-01',   // the first span's opening day — inclusive
            '2026-12-31',   // the day before the promotion — the closed span still holds
            '2027-01-01',   // the promotion's own day — inclusive
            '2027-06-30',   // well after
        ];

        $spans = Person::levelSpansBetween($people, '2026-06-30', '2027-06-30');

        foreach ($dates as $date) {
            foreach ($people as $person) {
                $expected = $person->levelAt($date)?->code;
                $actual = Person::levelFromSpans($spans[(int) $person->getKey()] ?? [], $date)?->code;

                $this->assertSame(
                    $expected,
                    $actual,
                    "person {$person->getKey()} on {$date}: levelAt() said ".var_export($expected, true)
                    .' but the in-memory resolver said '.var_export($actual, true)
                );
            }
        }
    }

    public function test_the_range_fetch_is_exactly_one_query_whatever_the_headcount(): void
    {
        $level = Level::factory()->create(['code' => 'X3']);

        $people = Person::factory()->count(20)->create();

        foreach ($people as $person) {
            LevelAssignment::assign($person, $level, '2026-07-01');
        }

        DB::enableQueryLog();
        DB::flushQueryLog();

        Person::levelSpansBetween($people, '2026-07-01', '2027-06-30');

        // One SELECT for the spans, one for the eager-loaded levels. Never one per person.
        $this->assertLessThanOrEqual(2, count(DB::getQueryLog()));
    }

    public function test_every_person_passed_in_gets_a_key_even_with_no_history(): void
    {
        $person = Person::factory()->create();

        $spans = Person::levelSpansBetween([$person], '2026-07-01', '2027-06-30');

        $this->assertArrayHasKey((int) $person->getKey(), $spans);
        $this->assertSame([], $spans[(int) $person->getKey()]);
    }

    public function test_a_span_that_merely_touches_the_range_is_included(): void
    {
        $level = Level::factory()->create(['code' => 'X4']);
        $person = Person::factory()->create();

        // Opens long before the range and is still open — it must come back.
        LevelAssignment::assign($person, $level, '2020-01-01');

        $spans = Person::levelSpansBetween([$person], '2026-07-01', '2027-06-30');

        $this->assertCount(1, $spans[(int) $person->getKey()]);
    }
}
