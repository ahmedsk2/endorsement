<?php

namespace Tests\Unit;

use App\Models\Institution;
use App\Support\Calendar;
use Carbon\CarbonImmutable;
use DateTime;
use Illuminate\Foundation\Testing\RefreshDatabase;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * Munawib AR-08: `App\Support\Calendar` is the ONLY place date-conversion logic lives.
 * tests/Feature/Build/CalendarIsTheOnlyConverterTest.php is the guard that nothing else may.
 *
 * Extends the Laravel TestCase (not bare PHPUnit) because Calendar::settings() reads
 * `Institution::current()` from the database.
 */
class CalendarTest extends TestCase
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

    public function test_parse_returns_midnight_in_the_instance_timezone(): void
    {
        $parsed = Calendar::parse('2026-08-08');

        $this->assertInstanceOf(CarbonImmutable::class, $parsed);
        $this->assertSame('00:00:00', $parsed->format('H:i:s'));
        $this->assertSame(Calendar::timezone(), $parsed->getTimezone()->getName());
    }

    public static function invalidDateProvider(): array
    {
        return [
            'relative' => ['+5 years'],
            'relative words' => ['last monday'],
            'slashes' => ['08/08/2026'],
            'unpadded' => ['2026-8-8'],
            'impossible day rolled forward by PHP' => ['2026-02-30'],
            'empty string' => [''],
        ];
    }

    #[DataProvider('invalidDateProvider')]
    public function test_parse_throws_on_anything_not_exactly_ymd(string $bad): void
    {
        $this->expectException(InvalidArgumentException::class);

        Calendar::parse($bad);
    }

    #[DataProvider('invalidDateProvider')]
    public function test_try_parse_returns_null_for_the_same_inputs(string $bad): void
    {
        $this->assertNull(Calendar::tryParse($bad));
    }

    public function test_try_parse_returns_a_date_for_a_good_input(): void
    {
        $this->assertInstanceOf(CarbonImmutable::class, Calendar::tryParse('2026-08-08'));
    }

    public function test_ymd_accepts_all_three_shapes_and_agrees(): void
    {
        $string = '2026-08-08';
        $carbon = Calendar::parse($string);
        $native = new DateTime($string);

        $this->assertSame($string, Calendar::ymd($string));
        $this->assertSame($string, Calendar::ymd($carbon));
        $this->assertSame($string, Calendar::ymd($native));
    }

    public function test_dates_between_is_inclusive_both_ends(): void
    {
        $this->assertSame(
            ['2026-08-01', '2026-08-02', '2026-08-03', '2026-08-04', '2026-08-05'],
            Calendar::datesBetween('2026-08-01', '2026-08-05')
        );
    }

    public function test_dates_between_returns_empty_when_to_precedes_from(): void
    {
        $this->assertSame([], Calendar::datesBetween('2026-08-05', '2026-08-01'));
    }

    public function test_hijri_at_offset_zero(): void
    {
        $this->institution(['hijri_offset_days' => 0]);

        $this->assertSame(['year' => 1448, 'month' => 2, 'day' => 25], Calendar::hijri('2026-08-08'));
    }

    public function test_hijri_at_offset_minus_one(): void
    {
        $this->institution(['hijri_offset_days' => -1]);

        $this->assertSame(['year' => 1448, 'month' => 2, 'day' => 24], Calendar::hijri('2026-08-08'));
    }

    /**
     * The whole reason the offset shifts the GREGORIAN instant before conversion, rather than
     * decrementing the resulting Hijri day number afterwards: at a month boundary,
     * decrementing the day number produces the impossible 1448-02-00. Shifting the instant
     * produces the real answer, 1448-01-29.
     */
    public function test_hijri_offset_shifts_the_instant_not_the_day_number_across_a_month_boundary(): void
    {
        $this->institution(['hijri_offset_days' => 0]);
        $this->assertSame(['year' => 1448, 'month' => 2, 'day' => 1], Calendar::hijri('2026-07-15'));

        $this->institution(['hijri_offset_days' => -1]);
        $this->assertSame(['year' => 1448, 'month' => 1, 'day' => 29], Calendar::hijri('2026-07-15'));
    }

    public function test_hijri_label_formats_day_month_name_year(): void
    {
        $this->institution(['hijri_offset_days' => -1]);

        $this->assertSame('24 Safar 1448', Calendar::hijriLabel('2026-08-08'));
    }

    public function test_hijri_label_is_empty_when_hijri_display_is_off(): void
    {
        $this->institution(['hijri_offset_days' => -1, 'hijri_enabled' => false]);

        $this->assertSame('', Calendar::hijriLabel('2026-08-08'));
    }

    public function test_is_weekend_under_the_default_friday_saturday(): void
    {
        $this->institution();

        // 2026-08-07 Friday, 2026-08-08 Saturday, 2026-08-09 Sunday.
        $this->assertTrue(Calendar::isWeekend('2026-08-07'));
        $this->assertTrue(Calendar::isWeekend('2026-08-08'));
        $this->assertFalse(Calendar::isWeekend('2026-08-09'));
    }

    public function test_is_weekend_flips_with_a_different_weekend_days_setting(): void
    {
        $this->institution(['weekend_days' => [6, 7]]); // Saturday, Sunday

        $this->assertFalse(Calendar::isWeekend('2026-08-07')); // Friday
        $this->assertTrue(Calendar::isWeekend('2026-08-08')); // Saturday
        $this->assertTrue(Calendar::isWeekend('2026-08-09')); // Sunday
    }

    public function test_every_method_works_with_no_institution_row_at_all(): void
    {
        $this->assertSame(0, Institution::count());

        $this->assertInstanceOf(CarbonImmutable::class, Calendar::parse('2026-08-08'));
        $this->assertSame(['2026-08-01'], Calendar::datesBetween('2026-08-01', '2026-08-01'));
        $this->assertIsArray(Calendar::hijri('2026-08-08'));
        $this->assertIsString(Calendar::hijriLabel('2026-08-08'));
        $this->assertIsBool(Calendar::isWeekend('2026-08-08'));
        $this->assertIsArray(Calendar::label('2026-08-08'));
    }

    public function test_label_returns_the_one_shape_every_screen_renders(): void
    {
        $this->institution(['hijri_offset_days' => -1]);

        // Task 7 extends this shape with holiday/day_type — HolidayTest pins the resolver
        // itself (a holiday's name, and HOL beating WE); this only pins that a day with no
        // matching holiday rule reports 'holiday' => null, 'day_type' => 'WE'.
        $this->assertSame(
            ['date' => '2026-08-08', 'hijri' => '24 Safar 1448', 'weekend' => true, 'holiday' => null, 'day_type' => 'WE'],
            Calendar::label('2026-08-08')
        );
    }
}
