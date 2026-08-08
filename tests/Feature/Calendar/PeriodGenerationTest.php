<?php

namespace Tests\Feature\Calendar;

use App\Models\Institution;
use App\Models\Period;
use App\Support\Calendar;
use App\Support\PeriodGenerator;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use InvalidArgumentException;
use RuntimeException;
use Tests\TestCase;

/**
 * Owner decision 2 (both period systems are first-class) and owner decision 4, ROUND 2
 * (2026-08-08, binding): the academic year resets to a FIXED start date each year rather than
 * drifting. Blocks run nominally four weeks; the year always begins on the department's set
 * date, so the FINAL block absorbs whatever days remain before the FOLLOWING year's start —
 * its length therefore VARIES year to year and must never be hardcoded to five weeks (35 days).
 *
 * The plan's original fixture assumed the run tiles a Gregorian year with a fixed five-week
 * final block (13 * 4 weeks + 1 * 5 weeks = 371 days). That is wrong under decision 4: a
 * Gregorian year is 365 or 366 days, and block 13 is whatever is left before the next year's
 * fixed start date. This test pins the RECOMPUTED numbers instead.
 */
class PeriodGenerationTest extends TestCase
{
    use RefreshDatabase;

    private const DEFAULT_BLOCK_WEEKS = [4, 4, 4, 4, 4, 4, 4, 4, 4, 4, 4, 4, 5];

    public function test_months_from_july_produces_twelve_contiguous_calendar_months(): void
    {
        $periods = PeriodGenerator::months(CarbonImmutable::parse('2026-07-01'));

        $this->assertCount(12, $periods);

        $this->assertSame(1, $periods[0]['position']);
        $this->assertSame('July 2026', $periods[0]['label']);
        $this->assertSame('2026-07-01', $periods[0]['starts_on']);
        $this->assertSame('2026-07-31', $periods[0]['ends_on']);
        $this->assertSame(Period::MONTH, $periods[0]['kind']);

        $this->assertSame(12, $periods[11]['position']);
        $this->assertSame('June 2027', $periods[11]['label']);
        $this->assertSame('2027-06-01', $periods[11]['starts_on']);
        $this->assertSame('2027-06-30', $periods[11]['ends_on']);

        // Contiguous: no gap, no overlap.
        foreach (range(0, 10) as $i) {
            $nextStart = CarbonImmutable::parse($periods[$i + 1]['starts_on']);
            $thisEnd = CarbonImmutable::parse($periods[$i]['ends_on']);

            $this->assertTrue($thisEnd->addDay()->equalTo($nextStart));
        }
    }

    public function test_months_handles_a_leap_february(): void
    {
        $periods = PeriodGenerator::months(CarbonImmutable::parse('2027-07-01'));

        $feb2028 = $periods[7];
        $this->assertSame('February 2028', $feb2028['label']);
        $this->assertSame('2028-02-01', $feb2028['starts_on']);
        $this->assertSame('2028-02-29', $feb2028['ends_on']);
    }

    /**
     * Decision 4's central claim: when the following year's fixed start date is supplied, the
     * final block's length is computed FROM IT, not from the nominal five-week entry in
     * $blockWeeks. QCH's academic year fixed-start is 1 July every year (department-set,
     * illustrative here).
     */
    public function test_week_blocks_final_block_absorbs_the_remainder_before_next_years_start(): void
    {
        $periods = PeriodGenerator::weekBlocks(
            CarbonImmutable::parse('2026-07-01'),
            self::DEFAULT_BLOCK_WEEKS,
            CarbonImmutable::parse('2027-07-01'),
        );

        $this->assertCount(13, $periods);

        foreach (range(0, 12) as $i) {
            $this->assertSame($i + 1, $periods[$i]['position']);
            $this->assertSame('Block '.($i + 1), $periods[$i]['label']);
            $this->assertSame(Period::WEEK_BLOCK, $periods[$i]['kind']);
        }

        // Blocks 1-12 are each exactly 28 days (four weeks), unaffected by decision 4.
        foreach (range(0, 11) as $i) {
            $start = CarbonImmutable::parse($periods[$i]['starts_on']);
            $end = CarbonImmutable::parse($periods[$i]['ends_on']);
            $this->assertSame(28, (int) $start->diffInDays($end) + 1, "block ".($i + 1)." should span 28 days");
        }

        // Block 13 is the recomputed remainder: 2027-06-02 .. 2027-06-30, 29 days — NOT the
        // plan's original 35-day (five-week) assumption. Pinned explicitly, the same way the
        // plan pinned 371 explicitly, because this is the number decision 4 exists to produce.
        $block13 = $periods[12];
        $this->assertSame('2027-06-02', $block13['starts_on']);
        $this->assertSame('2027-06-30', $block13['ends_on']);
        $start13 = CarbonImmutable::parse($block13['starts_on']);
        $end13 = CarbonImmutable::parse($block13['ends_on']);
        $this->assertSame(29, (int) $start13->diffInDays($end13) + 1);

        // The whole run spans exactly 365 days (2026-07-01 .. 2027-06-30 inclusive) — a
        // Gregorian year, stated explicitly rather than derived, so a regression that silently
        // reintroduces the fixed-five-week/371-day assumption is caught by inspection of this
        // test alone.
        $runStart = CarbonImmutable::parse($periods[0]['starts_on']);
        $runEnd = CarbonImmutable::parse($periods[12]['ends_on']);
        $this->assertSame(365, (int) $runStart->diffInDays($runEnd) + 1);
    }

    /**
     * Without a known next-year start (e.g. previewing a single year before the following one
     * is configured), the final block falls back to its NOMINAL length from $blockWeeks — five
     * weeks, 35 days, for QCH's default shape. This is a preview convenience, not the
     * authoritative length once the next year's start is known.
     */
    public function test_week_blocks_falls_back_to_the_nominal_length_without_a_next_year_start(): void
    {
        $periods = PeriodGenerator::weekBlocks(
            CarbonImmutable::parse('2026-07-01'),
            self::DEFAULT_BLOCK_WEEKS,
        );

        $block13 = $periods[12];
        $this->assertSame('2027-06-02', $block13['starts_on']);
        $this->assertSame('2027-07-06', $block13['ends_on']);
        $start13 = CarbonImmutable::parse($block13['starts_on']);
        $end13 = CarbonImmutable::parse($block13['ends_on']);
        $this->assertSame(35, (int) $start13->diffInDays($end13) + 1);
    }

    public function test_week_blocks_are_contiguous(): void
    {
        $periods = PeriodGenerator::weekBlocks(
            CarbonImmutable::parse('2026-07-01'),
            self::DEFAULT_BLOCK_WEEKS,
            CarbonImmutable::parse('2027-07-01'),
        );

        foreach (range(0, 11) as $i) {
            $thisEnd = CarbonImmutable::parse($periods[$i]['ends_on']);
            $nextStart = CarbonImmutable::parse($periods[$i + 1]['starts_on']);

            $this->assertTrue($thisEnd->addDay()->equalTo($nextStart));
        }
    }

    public function test_week_blocks_rejects_an_empty_list(): void
    {
        $this->expectException(InvalidArgumentException::class);

        PeriodGenerator::weekBlocks(CarbonImmutable::parse('2026-07-01'), []);
    }

    public function test_week_blocks_rejects_a_block_shorter_than_one_week(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('0');

        PeriodGenerator::weekBlocks(CarbonImmutable::parse('2026-07-01'), [4, 0, 4]);
    }

    public function test_week_blocks_rejects_a_block_longer_than_eight_weeks(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('9');

        PeriodGenerator::weekBlocks(CarbonImmutable::parse('2026-07-01'), [4, 9, 4]);
    }

    public function test_week_blocks_rejects_more_than_twenty_six_blocks(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('27');

        PeriodGenerator::weekBlocks(CarbonImmutable::parse('2026-07-01'), array_fill(0, 27, 4));
    }

    /**
     * A next-year start that leaves no room at all for the final block (on or before where it
     * would nominally start) is a genuine input error, not a warning-worthy scheduling choice —
     * distinct from warningsAgainstNeighbours(), which reports against periods already
     * PERSISTED for a neighbouring year.
     */
    public function test_week_blocks_rejects_a_next_year_start_that_leaves_no_room_for_the_final_block(): void
    {
        $this->expectException(InvalidArgumentException::class);

        PeriodGenerator::weekBlocks(
            CarbonImmutable::parse('2026-07-01'),
            [4, 4],
            CarbonImmutable::parse('2026-07-01'), // same as the run's own start
        );
    }

    public function test_calendar_period_for_resolves_a_date_inside_a_persisted_block(): void
    {
        $period = Period::create([
            'institution_id' => null,
            'academic_year' => '2026-2027',
            'kind' => Period::WEEK_BLOCK,
            'position' => 1,
            'label' => 'Block 1',
            'starts_on' => '2026-07-01',
            'ends_on' => '2026-07-28',
        ]);

        $resolved = Calendar::periodFor('2026-07-15');
        $this->assertNotNull($resolved);
        $this->assertSame($period->id, $resolved->id);

        // Both bounds inclusive — the same idiom as Person::levelAt().
        $this->assertSame($period->id, Calendar::periodFor('2026-07-01')?->id);
        $this->assertSame($period->id, Calendar::periodFor('2026-07-28')?->id);

        $this->assertNull(Calendar::periodFor('2026-06-30'));
        $this->assertNull(Calendar::periodFor('2026-07-29'));
    }

    public function test_persisting_an_overlapping_period_throws_naming_both_labels(): void
    {
        Period::create([
            'institution_id' => null,
            'academic_year' => '2026-2027',
            'kind' => Period::WEEK_BLOCK,
            'position' => 1,
            'label' => 'Block 1',
            'starts_on' => '2026-07-01',
            'ends_on' => '2026-07-28',
        ]);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Block 1');
        $this->expectExceptionMessage('Block 2 (clashing)');

        Period::create([
            'institution_id' => null,
            'academic_year' => '2026-2027',
            'kind' => Period::WEEK_BLOCK,
            'position' => 2,
            'label' => 'Block 2 (clashing)',
            'starts_on' => '2026-07-20',
            'ends_on' => '2026-08-16',
        ]);
    }

    public function test_a_different_academic_year_may_reuse_the_same_dates(): void
    {
        Period::create([
            'institution_id' => null,
            'academic_year' => '2026-2027',
            'kind' => Period::WEEK_BLOCK,
            'position' => 1,
            'label' => 'Block 1',
            'starts_on' => '2026-07-01',
            'ends_on' => '2026-07-28',
        ]);

        // Same institution (null), different academic_year label — no clash, because the
        // unique/overlap scope is (institution_id, academic_year).
        $other = Period::create([
            'institution_id' => null,
            'academic_year' => 'Testing sandbox',
            'kind' => Period::WEEK_BLOCK,
            'position' => 1,
            'label' => 'Block 1',
            'starts_on' => '2026-07-01',
            'ends_on' => '2026-07-28',
        ]);

        $this->assertTrue($other->exists);
    }

    public function test_warnings_against_neighbours_reports_an_overlap_as_a_warning_not_an_exception(): void
    {
        $previousYearLast = Period::create([
            'institution_id' => null,
            'academic_year' => '2025-2026',
            'kind' => Period::WEEK_BLOCK,
            'position' => 13,
            'label' => 'Block 13',
            'starts_on' => '2026-06-01',
            'ends_on' => '2026-07-10', // runs past the next year's intended start
        ]);

        $generated = PeriodGenerator::weekBlocks(
            CarbonImmutable::parse('2026-07-01'),
            self::DEFAULT_BLOCK_WEEKS,
            CarbonImmutable::parse('2027-07-01'),
        );

        $warnings = PeriodGenerator::warningsAgainstNeighbours($generated, $previousYearLast, null);

        $this->assertNotEmpty($warnings);
        $this->assertStringContainsString('Block 13', $warnings[0]);
    }

    public function test_warnings_against_neighbours_is_empty_when_everything_lines_up(): void
    {
        $previousYearLast = Period::create([
            'institution_id' => null,
            'academic_year' => '2025-2026',
            'kind' => Period::WEEK_BLOCK,
            'position' => 13,
            'label' => 'Block 13',
            'starts_on' => '2026-06-01',
            'ends_on' => '2026-06-30', // ends the day before the next run starts
        ]);

        $generated = PeriodGenerator::weekBlocks(
            CarbonImmutable::parse('2026-07-01'),
            self::DEFAULT_BLOCK_WEEKS,
            CarbonImmutable::parse('2027-07-01'),
        );

        $warnings = PeriodGenerator::warningsAgainstNeighbours($generated, $previousYearLast, null);

        $this->assertSame([], $warnings);
    }

    public function test_calendar_reads_the_institutions_period_settings(): void
    {
        $institution = Institution::create([
            'code' => 'TEST',
            'name' => 'Test Hospital',
            'active' => true,
            'academic_year_start' => '2026-07-01',
        ]);

        $this->assertSame(Institution::PERIOD_WEEK_BLOCKS, Calendar::periodType());
        $this->assertSame(self::DEFAULT_BLOCK_WEEKS, Calendar::blockWeeks());
        $this->assertSame('2026-07-01', Calendar::academicYearStart()?->format(Calendar::YMD));

        $institution->delete();
    }

    public function test_calendar_academic_year_start_is_null_by_default(): void
    {
        $this->assertNull(Calendar::academicYearStart());
    }

    public function test_calendar_periods_for_year_returns_only_that_years_periods_ordered(): void
    {
        Period::create([
            'institution_id' => null, 'academic_year' => '2026-2027', 'kind' => Period::WEEK_BLOCK,
            'position' => 2, 'label' => 'Block 2', 'starts_on' => '2026-07-29', 'ends_on' => '2026-08-25',
        ]);
        Period::create([
            'institution_id' => null, 'academic_year' => '2026-2027', 'kind' => Period::WEEK_BLOCK,
            'position' => 1, 'label' => 'Block 1', 'starts_on' => '2026-07-01', 'ends_on' => '2026-07-28',
        ]);
        Period::create([
            'institution_id' => null, 'academic_year' => '2027-2028', 'kind' => Period::WEEK_BLOCK,
            'position' => 1, 'label' => 'Block 1', 'starts_on' => '2027-07-01', 'ends_on' => '2027-07-28',
        ]);

        $periods = Calendar::periodsForYear('2026-2027');

        $this->assertCount(2, $periods);
        $this->assertSame('Block 1', $periods[0]->label);
        $this->assertSame('Block 2', $periods[1]->label);
    }
}
