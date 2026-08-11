<?php

namespace Tests\Feature\Calendar;

use App\Models\Institution;
use App\Support\Calendar;
use Illuminate\Foundation\Testing\RefreshDatabase;
use InvalidArgumentException;
use Tests\TestCase;

/**
 * P1e Task 1, Decision A. A clinic's weekday is a plain ISO-8601 integer (Monday = 1 … Sunday = 7)
 * and needs no conversion to store — but ORDERING and LABELLING the department's week are
 * `App\Support\Calendar`'s and go nowhere else, because a screen that builds its own day list is
 * the second converter AR-08 exists to forbid (`CalendarIsTheOnlyConverterTest`).
 *
 * The property this whole file is really about: **nothing stored depends on the week start.**
 * `clinics.weekday` holds an absolute ISO integer; only presentation rotates. A department that
 * changes `institutions.weekend_days` re-orders every clinic map immediately, with no stored value
 * changing and no data migration — there is no `institutions.week_start` column to keep in step,
 * because `Calendar::weekStartIsoDay()` derives the start from the weekend (P1d Task 2).
 */
class WeekdayVocabularyTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Calendar::flush();
    }

    /** Create (or update) the single institution row and drop Calendar's memoized settings. */
    private function institution(array $overrides = []): Institution
    {
        $existing = Institution::first();

        if ($existing !== null) {
            $existing->update($overrides);
            Calendar::flush();

            return $existing->refresh();
        }

        $institution = Institution::create(array_merge([
            'code' => 'TEST',
            'name' => 'Test Hospital',
            'active' => true,
        ], $overrides));

        Calendar::flush();

        return $institution;
    }

    /** @return list<int> */
    private function isoOrder(array $columns): array
    {
        return array_map(static fn (array $column): int => $column['iso'], $columns);
    }

    /**
     * The one test that stops a THIRD numbering scheme reaching this module. `Calendar::
     * weekendDays()`, `::weekStartIsoDay()` and `::isWeekend()` are all ISO-8601 already; Carbon's
     * `dayOfWeek` puts Sunday at 0 and is a second scheme that must never be compared against a
     * stored weekday.
     */
    public function test_monday_is_one_and_sunday_is_seven(): void
    {
        $this->institution(['weekend_days' => [5, 6]]);

        $this->assertSame('Monday', Calendar::weekdayLabel(1));
        $this->assertSame('Sunday', Calendar::weekdayLabel(7));

        // 2026-08-09 is a Sunday. ISO calls it 7; Carbon's dayOfWeek calls it 0 — and 0 is not a
        // weekday number this module accepts at all, which is what keeps the two from being
        // silently interchangeable.
        $sunday = Calendar::parse('2026-08-09');

        $this->assertSame(7, $sunday->isoWeekday());
        $this->assertSame(0, $sunday->dayOfWeek);
        $this->assertSame('Sunday', Calendar::weekdayLabel($sunday->isoWeekday()));

        $this->expectException(InvalidArgumentException::class);
        Calendar::weekdayLabel($sunday->dayOfWeek);
    }

    public function test_the_columns_start_at_the_departments_own_week_start(): void
    {
        // QCH's default: Friday(5) + Saturday(6) off, so the week begins on Sunday(7).
        $this->institution(['weekend_days' => [5, 6]]);

        $this->assertSame(7, Calendar::weekStartIsoDay());
        $this->assertSame([7, 1, 2, 3, 4, 5, 6], $this->isoOrder(Calendar::weekdayColumns()));
        $this->assertSame(
            ['Sunday', 'Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday', 'Saturday'],
            array_map(static fn (array $c): string => $c['label'], Calendar::weekdayColumns())
        );
        $this->assertSame(
            ['Sun', 'Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat'],
            array_map(static fn (array $c): string => $c['short'], Calendar::weekdayColumns())
        );

        // A Saturday(6) + Sunday(7) weekend begins its week on Monday(1).
        $this->institution(['weekend_days' => [6, 7]]);

        $this->assertSame(1, Calendar::weekStartIsoDay());
        $this->assertSame([1, 2, 3, 4, 5, 6, 7], $this->isoOrder(Calendar::weekdayColumns()));
    }

    /** Read from `weekendDays()`, one definition, never recomputed from the week start. */
    public function test_every_column_says_whether_it_is_a_weekend_day(): void
    {
        $this->institution(['weekend_days' => [5, 6]]);

        $weekendByIso = [];
        foreach (Calendar::weekdayColumns() as $column) {
            $weekendByIso[$column['iso']] = $column['weekend'];
        }

        // Keyed by ISO day, not by column position — the ORDER is the previous test's subject and
        // `assertSame` on an array compares key order too.
        ksort($weekendByIso);

        $this->assertSame(
            [1 => false, 2 => false, 3 => false, 4 => false, 5 => true, 6 => true, 7 => false],
            $weekendByIso
        );

        // And it moves with the configuration rather than with the column position: under a
        // Saturday–Sunday weekend the FIRST column (Monday) is a working day and the last two
        // are not, where under [5, 6] the first column (Sunday) was also a working day.
        $this->institution(['weekend_days' => [6, 7]]);

        $this->assertSame(
            [false, false, false, false, false, true, true],
            array_map(static fn (array $c): bool => $c['weekend'], Calendar::weekdayColumns())
        );
    }

    public function test_there_are_always_seven_columns_and_no_iso_day_repeats(): void
    {
        foreach ([[5, 6], [6, 7], [5], [], [1, 2, 3, 4, 5, 6, 7]] as $weekend) {
            $this->institution(['weekend_days' => $weekend]);

            $columns = Calendar::weekdayColumns();
            $isos = $this->isoOrder($columns);

            $this->assertCount(7, $columns, 'weekend_days '.json_encode($weekend));
            $this->assertSame($isos, array_values(array_unique($isos)), 'weekend_days '.json_encode($weekend));

            sort($isos);
            $this->assertSame([1, 2, 3, 4, 5, 6, 7], $isos, 'weekend_days '.json_encode($weekend));
        }
    }

    /**
     * The survival property Decision A rests on. Changing the weekend re-orders the presentation
     * and changes NOTHING else: the same ISO integer still means the same day with the same label,
     * so a stored `clinics.weekday` needs no migration and cannot be silently re-pointed.
     */
    public function test_changing_the_weekend_reorders_the_columns_with_no_stored_value_changing(): void
    {
        $this->institution(['weekend_days' => [5, 6]]);
        $sundayStart = Calendar::weekdayColumns();

        $this->institution(['weekend_days' => [6, 7]]);
        $mondayStart = Calendar::weekdayColumns();

        // The order moved …
        $this->assertNotSame($this->isoOrder($sundayStart), $this->isoOrder($mondayStart));
        $this->assertSame([7, 1, 2, 3, 4, 5, 6], $this->isoOrder($sundayStart));
        $this->assertSame([1, 2, 3, 4, 5, 6, 7], $this->isoOrder($mondayStart));

        // … and the vocabulary did not: iso -> label is identical under both configurations, so a
        // row holding `weekday = 2` still means Tuesday either side of the change.
        $labelByIso = static function (array $columns): array {
            $out = [];
            foreach ($columns as $column) {
                $out[$column['iso']] = $column['label'];
            }
            ksort($out);

            return $out;
        };

        $this->assertSame($labelByIso($sundayStart), $labelByIso($mondayStart));
        $this->assertSame('Tuesday', Calendar::weekdayLabel(2));

        $this->institution(['weekend_days' => [5, 6]]);
        $this->assertSame('Tuesday', Calendar::weekdayLabel(2));
    }

    /**
     * `weekendDays()` can genuinely return `[]` — its own comment (Calendar.php) explains why the
     * settings reader uses `??` rather than `?:` so that `weekStartIsoDay()`'s Monday fallback is
     * reachable rather than dead code.
     */
    public function test_an_empty_weekend_list_still_produces_seven_ordered_columns(): void
    {
        $this->institution(['weekend_days' => []]);

        $columns = Calendar::weekdayColumns();

        $this->assertSame(1, Calendar::weekStartIsoDay());
        $this->assertSame([1, 2, 3, 4, 5, 6, 7], $this->isoOrder($columns));
        $this->assertSame(
            [false, false, false, false, false, false, false],
            array_map(static fn (array $c): bool => $c['weekend'], $columns)
        );
    }

    public function test_an_out_of_range_iso_day_is_refused(): void
    {
        $this->institution(['weekend_days' => [5, 6]]);

        foreach ([0, 8, -1] as $iso) {
            try {
                Calendar::weekdayLabel($iso);
                $this->fail("weekdayLabel({$iso}) should have thrown InvalidArgumentException");
            } catch (InvalidArgumentException) {
                $this->addToAssertionCount(1);
            }
        }
    }

    /**
     * Decision A's boundary, asserted rather than described: a weekday NUMBER is a recurrence-rule
     * component, not a date, so no conversion happens when it is stored — `weekdayColumns()` is
     * pure vocabulary plus a rotation and never touches "today".
     */
    public function test_the_columns_do_not_depend_on_the_current_date(): void
    {
        $this->institution(['weekend_days' => [5, 6]]);

        $first = $this->withTimezone('UTC', static fn () => Calendar::weekdayColumns());
        $second = $this->withTimezone('Pacific/Kiritimati', static fn () => Calendar::weekdayColumns());

        $this->assertSame($first, $second);
    }
}
