<?php

namespace Tests\Feature\Calendar;

use App\Models\Holiday;
use App\Models\Institution;
use App\Support\Calendar;
use App\Support\PeriodGenerator;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Task 8 (P1a). Design §4.3 / AR-03 make a SHARED golden-fixture suite the mechanism that stops
 * `App\Support\Calendar` (this file) and P2's `packages/engine` TypeScript mirror drifting apart
 * — Decision A defers the mirror itself to P2, on the condition that P1a builds this corpus now,
 * so the mirror has something to be cross-validated against on the day it is written.
 *
 * `tests/fixtures/calendar/golden.json` is the durable contract, not test scaffolding: it is
 * plain JSON, readable by both PHP and a future TypeScript harness with no framework. THIS class
 * only asserts it against the PHP implementation; P2's mirror asserts the same file against its
 * own. A change to golden.json that is not matched by an equivalent change in P2's mirror is
 * exactly the drift this file exists to catch.
 *
 * Every value in golden.json was produced by running PeriodGenerator/Calendar and reading the
 * result back (see the plan's Amendments/Task 8 notes) — never copied from the plan on faith,
 * because the plan's own original fixture was written under the superseded 371-day arithmetic
 * (round-2 owner decision 4 fixed the academic year to a set start date, so block 13 absorbs
 * whatever remains rather than always running five weeks).
 */
class GoldenFixtureTest extends TestCase
{
    use RefreshDatabase;

    private const FIXTURE_PATH = __DIR__.'/../../fixtures/calendar/golden.json';

    private array $fixture;

    protected function setUp(): void
    {
        parent::setUp();

        $raw = file_get_contents(self::FIXTURE_PATH);
        $this->assertNotFalse($raw, 'golden.json must exist and be readable.');

        $this->fixture = json_decode($raw, true, flags: JSON_THROW_ON_ERROR);
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

    public function test_fixture_declares_the_riyadh_timezone(): void
    {
        // The corpus is meaningless without pinning which timezone it was computed under —
        // Hijri conversion is timezone-invariant (DayBoundaryTest) but Gregorian day-boundary
        // arithmetic is not, and a consumer needs to know what "Asia/Riyadh" the day-boundary
        // cases below were computed against.
        $this->assertSame('Asia/Riyadh', $this->fixture['timezone']);
    }

    /**
     * A marker so a future shape change to golden.json is visible to BOTH independent
     * consumers (this file and P2's TypeScript mirror), rather than one silently drifting
     * onto a JSON shape the other has never seen.
     */
    public function test_fixture_declares_a_version(): void
    {
        $this->assertSame(1, $this->fixture['version']);
    }

    public function test_date_cases(): void
    {
        foreach ($this->fixture['cases'] as $case) {
            $this->institution([
                'hijri_offset_days' => $case['settings']['hijri_offset_days'],
                'weekend_days' => $case['settings']['weekend_days'],
            ]);

            foreach ($case['dates'] as $expected) {
                $date = $expected['date'];

                $h = Calendar::hijri($date);
                $actualHijri = sprintf('%04d-%02d-%02d', $h['year'], $h['month'], $h['day']);

                $this->assertSame($expected['hijri'], $actualHijri, "hijri({$date})");
                $this->assertSame(
                    $expected['iso_weekday'],
                    CarbonImmutable::parse($date)->isoWeekday(),
                    "iso_weekday({$date})"
                );
                $this->assertSame($expected['weekend'], Calendar::isWeekend($date), "isWeekend({$date})");
                $this->assertSame($expected['day_type'], Calendar::dayType($date), "dayType({$date})");
            }
        }
    }

    /**
     * P1d Task 2: the department's week is derived from `weekend_days`, not constant — a
     * Friday+Saturday weekend and a Saturday+Sunday weekend start their weeks on different
     * days. Every value here was produced by running `Calendar::weekOf()`, not written by hand.
     */
    public function test_the_week_fixtures_reproduce(): void
    {
        foreach ($this->fixture['weeks'] as $case) {
            $this->institution(['weekend_days' => $case['weekend_days']]);

            $this->assertSame(
                $case['week_start_iso_day'],
                Calendar::weekStartIsoDay(),
                "weekStartIsoDay() for weekend_days ".json_encode($case['weekend_days'])
            );

            $week = Calendar::weekOf($case['of']);

            $this->assertSame($case['starts_on'], $week['starts_on'], "weekOf({$case['of']})['starts_on']");
            $this->assertSame($case['ends_on'], $week['ends_on'], "weekOf({$case['of']})['ends_on']");
        }
    }

    /**
     * The whole reason `Calendar::hijri()` applies the offset to the GREGORIAN instant before
     * conversion, never by decrementing the resulting Hijri day number afterwards: doing the
     * latter produces the impossible 1448-02-00 at exactly this boundary.
     */
    public function test_hijri_month_boundary_is_resolved_by_shifting_the_instant(): void
    {
        $boundary = $this->fixture['hijri_month_boundary'];

        $this->institution(['hijri_offset_days' => 0]);
        $h = Calendar::hijri($boundary['offset_0']['date']);
        $this->assertSame(
            $boundary['offset_0']['hijri'],
            sprintf('%04d-%02d-%02d', $h['year'], $h['month'], $h['day'])
        );

        $this->institution(['hijri_offset_days' => -1]);
        $h = Calendar::hijri($boundary['offset_-1']['date']);
        $this->assertSame(
            $boundary['offset_-1']['hijri'],
            sprintf('%04d-%02d-%02d', $h['year'], $h['month'], $h['day'])
        );
    }

    /**
     * The concrete statement of why `APP_TIMEZONE` cannot change after the first clinical write
     * (docs/RUNBOOK-PROVISION.md:25): the same instant files under a DIFFERENT calendar day
     * depending on which timezone is active, and `handover_signoffs` carries
     * UNIQUE(unit_id, handover_date) on that day.
     */
    public function test_day_boundary_cases(): void
    {
        foreach ($this->fixture['day_boundary_cases'] as $case) {
            foreach ($case['today_ymd_by_timezone'] as $timezone => $expectedYmd) {
                $actual = $this->withTimezone($timezone, function () use ($case) {
                    CarbonImmutable::setTestNow(CarbonImmutable::parse($case['instant_utc']));
                    $today = Calendar::todayYmd();
                    CarbonImmutable::setTestNow();

                    return $today;
                });

                $this->assertSame($expectedYmd, $actual, "todayYmd() at {$case['instant_utc']} under {$timezone}");
            }
        }
    }

    public function test_holiday_cases(): void
    {
        foreach ($this->fixture['holiday_cases'] as $case) {
            $rule = $case['rule'];

            // No `??` fallback here: golden.json supplies `year` and `duration_days` on every
            // rule explicitly (M3) precisely so a missing key fails loudly (undefined array
            // key) instead of silently reverting to a default a second implementation cannot
            // see.
            $holiday = Holiday::create([
                'name' => 'Golden fixture holiday',
                'calendar' => $rule['calendar'],
                'month' => $rule['month'],
                'day' => $rule['day'],
                'year' => $rule['year'],
                'duration_days' => $rule['duration_days'],
                'equity_tracked' => true,
                'active' => true,
            ]);

            foreach ($case['expect'] as $expected) {
                $this->institution([
                    'hijri_offset_days' => $expected['settings']['hijri_offset_days'],
                    'weekend_days' => $expected['settings']['weekend_days'],
                ]);

                $this->assertSame(
                    $expected['holiday'],
                    Calendar::isHoliday($expected['date']),
                    "isHoliday({$expected['date']}) for rule ".json_encode($rule)
                );

                if (isset($expected['day_type'])) {
                    $this->assertSame(
                        $expected['day_type'],
                        Calendar::dayType($expected['date']),
                        "dayType({$expected['date']})"
                    );
                }
            }

            $holiday->delete();
            Calendar::flush();
        }
    }

    public function test_period_runs(): void
    {
        foreach ($this->fixture['period_runs'] as $run) {
            if ($run['kind'] === 'week_block') {
                $nextYearStart = $run['next_year_starts_on'] === null
                    ? null
                    : CarbonImmutable::parse($run['next_year_starts_on']);

                $periods = PeriodGenerator::weekBlocks(
                    CarbonImmutable::parse($run['starts_on']),
                    $run['block_weeks'],
                    $nextYearStart,
                );
            } else {
                $periods = PeriodGenerator::months(
                    CarbonImmutable::parse($run['starts_on']),
                    $run['count'] ?? 12,
                );
            }

            $expect = $run['expect'];

            $this->assertCount($expect['count'], $periods, $run['_description'] ?? $run['kind']);

            if (isset($expect['first'])) {
                $this->assertSame($expect['first']['position'], $periods[0]['position']);
                $this->assertSame($expect['first']['label'], $periods[0]['label']);
                $this->assertSame($expect['first']['starts_on'], $periods[0]['starts_on']);
                $this->assertSame($expect['first']['ends_on'], $periods[0]['ends_on']);
            }

            if (isset($expect['last'])) {
                $lastActual = $periods[count($periods) - 1];
                $this->assertSame($expect['last']['position'], $lastActual['position']);
                $this->assertSame($expect['last']['label'], $lastActual['label']);
                $this->assertSame($expect['last']['starts_on'], $lastActual['starts_on']);
                $this->assertSame($expect['last']['ends_on'], $lastActual['ends_on']);
            }

            if (isset($expect['total_days'])) {
                $start = CarbonImmutable::parse($periods[0]['starts_on']);
                $end = CarbonImmutable::parse($periods[count($periods) - 1]['ends_on']);
                $this->assertSame($expect['total_days'], (int) $start->diffInDays($end) + 1);
            }

            if (isset($expect['at_position_8'])) {
                $entry = $periods[7];
                $this->assertSame($expect['at_position_8']['position'], $entry['position']);
                $this->assertSame($expect['at_position_8']['label'], $entry['label']);
                $this->assertSame($expect['at_position_8']['starts_on'], $entry['starts_on']);
                $this->assertSame($expect['at_position_8']['ends_on'], $entry['ends_on']);
            }
        }
    }

    /**
     * M3: strict parsing is the most safety-critical behaviour in the module (Calendar.php's
     * own docblock — strtotime() leniency accepted "+5 years" and created real backdated
     * clinical rows). A lenient mirror would otherwise pass the whole rest of this corpus, so
     * both the throwing and the non-throwing entry point must reject each of these.
     */
    public function test_parse_rejects(): void
    {
        foreach ($this->fixture['parse_rejects']['inputs'] as $input) {
            $this->assertNull(Calendar::tryParse($input), "tryParse({$input}) should return null");

            try {
                Calendar::parse($input);
                $this->fail("parse({$input}) should have thrown InvalidArgumentException");
            } catch (\InvalidArgumentException) {
                $this->addToAssertionCount(1);
            }
        }
    }

    /**
     * M3: the Umm al-Qura month-name vocabulary is part of the contract — a mirror's own
     * month-name table needs the same 12 strings, not a hand-transcribed guess.
     */
    public function test_hijri_labels_vocabulary(): void
    {
        $expected = [];
        foreach ($this->fixture['hijri_labels']['months'] as $month => $name) {
            $expected[(int) $month] = $name;
        }

        $this->assertSame($expected, __('calendar.hijri_months'));
    }
}
