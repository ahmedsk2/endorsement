<?php

namespace Tests\Feature\Calendar;

use App\Support\Calendar;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * Finding 1: the whole PHP suite ran at UTC while production runs at +03:00, and no test
 * crossed a day boundary at a positive offset — a calendar module "proven" only at +00:00 and
 * shipped to +03:00. This is that proof. It comes before any task that renders a date,
 * because a day-boundary regression at +03:00 is invisible in a suite that runs at +00:00.
 *
 * PHPUnit 12 requires the attribute form of data providers — `@dataProvider` docblocks are no
 * longer parsed and fail silently with "Too few arguments" (P0d Task 1 amendment).
 */
class DayBoundaryTest extends TestCase
{
    use RefreshDatabase;

    /** The 00:00-03:00 instant where UTC's calendar day and Riyadh's disagree. */
    private const AMBIGUOUS_INSTANT = '2026-08-08T22:30:00Z';

    protected function tearDown(): void
    {
        CarbonImmutable::setTestNow();

        parent::tearDown();
    }

    public static function timezoneProvider(): array
    {
        return [
            'UTC' => ['UTC'],
            'Asia/Riyadh' => ['Asia/Riyadh'],
        ];
    }

    #[DataProvider('timezoneProvider')]
    public function test_today_ymd_disagrees_in_the_ambiguous_window(string $timezone): void
    {
        $this->withTimezone($timezone, function () use ($timezone) {
            CarbonImmutable::setTestNow(CarbonImmutable::parse(self::AMBIGUOUS_INSTANT));

            $expected = $timezone === 'UTC' ? '2026-08-08' : '2026-08-09';

            $this->assertSame($expected, Calendar::todayYmd());
        });
    }

    /**
     * Same calendar date, different timezone: `parse()` produces LOCAL midnight, so the two
     * timezones' midnights are different instants 10800 seconds (3 hours) apart. An
     * implementation that quietly normalized to UTC internally would make this equal.
     */
    #[DataProvider('timezoneProvider')]
    public function test_parse_produces_midnight_in_the_active_timezone(string $timezone): void
    {
        $timestamp = $this->withTimezone(
            $timezone,
            fn () => Calendar::parse('2026-08-08')->getTimestamp()
        );

        $utcTimestamp = $this->withTimezone(
            'UTC',
            fn () => Calendar::parse('2026-08-08')->getTimestamp()
        );

        $expectedDelta = $timezone === 'UTC' ? 0 : 10800;

        $this->assertSame($expectedDelta, abs($utcTimestamp - $timestamp));
    }

    /**
     * Calendar arithmetic, not instant arithmetic. An implementation that drifted into
     * comparing/iterating raw Unix timestamps instead of calendar days would enumerate a
     * different number of entries once the two timezones' day boundaries stopped lining up —
     * this stays identical under both because it never leaves calendar-day space.
     */
    #[DataProvider('timezoneProvider')]
    public function test_dates_between_is_timezone_invariant(string $timezone): void
    {
        $result = $this->withTimezone(
            $timezone,
            fn () => Calendar::datesBetween('2026-08-01', '2026-08-05')
        );

        $this->assertSame(['2026-08-01', '2026-08-02', '2026-08-03', '2026-08-04', '2026-08-05'], $result);
    }

    /**
     * Hijri conversion is anchored at LOCAL NOON specifically so no timezone or transition
     * edge can move the resulting day — it must be identical under both timezones for the
     * same Y-m-d input.
     */
    #[DataProvider('timezoneProvider')]
    public function test_hijri_is_timezone_invariant(string $timezone): void
    {
        $result = $this->withTimezone(
            $timezone,
            fn () => Calendar::hijri('2026-08-08')
        );

        $this->assertSame(['year' => 1448, 'month' => 2, 'day' => 25], $result);
    }

    /**
     * The concrete statement of why APP_TIMEZONE cannot change after the first clinical write
     * (docs/RUNBOOK-PROVISION.md:25): the same instant files under a DIFFERENT calendar day
     * depending on which timezone is active, and `handover_signoffs` carries
     * UNIQUE(unit_id, handover_date) on that day. A write at 22:30 UTC is 08-08 under UTC and
     * 08-09 under Riyadh — reconfiguring the instance timezone after go-live silently
     * reassigns which day every existing near-midnight row belongs to.
     */
    public function test_a_write_at_2230_utc_files_under_the_next_day_in_riyadh(): void
    {
        $utcDay = $this->withTimezone('UTC', function () {
            CarbonImmutable::setTestNow(CarbonImmutable::parse(self::AMBIGUOUS_INSTANT));

            return Calendar::todayYmd();
        });

        $riyadhDay = $this->withTimezone('Asia/Riyadh', function () {
            CarbonImmutable::setTestNow(CarbonImmutable::parse(self::AMBIGUOUS_INSTANT));

            return Calendar::todayYmd();
        });

        $this->assertSame('2026-08-08', $utcDay);
        $this->assertSame('2026-08-09', $riyadhDay);
        $this->assertNotSame($utcDay, $riyadhDay);
    }
}
