<?php

namespace App\Support;

use App\Models\Period;
use Carbon\CarbonImmutable;
use InvalidArgumentException;

/**
 * Pure period-boundary arithmetic for MR-01 (owner decision 2: both period systems are
 * first-class). No database writes — a preview screen and the committing caller share this one
 * definition.
 *
 * OWNER DECISION 4 (round 2, 2026-08-08, binding): the academic year resets to a FIXED start
 * date each year rather than drifting. Week-blocks run nominally four weeks each, but the year
 * always begins on the department's set date, so the FINAL block absorbs whatever days remain
 * before the FOLLOWING year's start — its length therefore VARIES year to year and must never
 * be hardcoded to five weeks (35 days). `weekBlocks()` computes the final block from
 * `$nextYearStart` when it is supplied; the last entry of `$blockWeeks` is a nominal fallback
 * only, used for a preview before the next year's start date is known.
 *
 * (An earlier reading — reconnaissance finding 7, superseded by decision 4 — assumed a
 * thirteen-block run of 4*12 + 5 weeks tiles a 371-day "academic year" that drifts forward
 * roughly six days annually. A Gregorian year is 365 or 366 days; decision 4 settles that the
 * department resets to a fixed date instead, so block 13 is whatever is left, not a constant.)
 */
final class PeriodGenerator
{
    private const MIN_BLOCK_WEEKS = 1;

    private const MAX_BLOCK_WEEKS = 8;

    private const MAX_BLOCKS = 26;

    /** @return list<array{position:int,label:string,starts_on:string,ends_on:string,kind:string}> */
    public static function months(CarbonImmutable $start, int $count = 12): array
    {
        $out = [];
        $cursor = $start->startOfDay();

        for ($i = 1; $i <= $count; $i++) {
            $monthStart = $cursor;
            $monthEnd = $monthStart->addMonthNoOverflow()->subDay();

            $out[] = [
                'position' => $i,
                'label' => $monthStart->format('F Y'),
                'starts_on' => $monthStart->format(Calendar::YMD),
                'ends_on' => $monthEnd->format(Calendar::YMD),
                'kind' => Period::MONTH,
            ];

            $cursor = $monthEnd->addDay();
        }

        return $out;
    }

    /**
     * @param  list<int>  $blockWeeks  nominal length in weeks per block, in order (MR-01:
     *         lengths MAY vary within a year). The LAST entry is a fallback only — used when
     *         $nextYearStart is not yet known. Decision 4 makes the real final block whatever
     *         remains before the following year's fixed start date, computed FROM
     *         $nextYearStart, never from this constant.
     * @param  CarbonImmutable|null  $nextYearStart  the following academic year's fixed start
     *         date. When given, the final block's `ends_on` is the day before it. When null
     *         (previewing a year before the next one has a configured start), the final block
     *         falls back to its nominal length from $blockWeeks.
     * @return list<array{position:int,label:string,starts_on:string,ends_on:string,kind:string}>
     */
    public static function weekBlocks(
        CarbonImmutable $start,
        array $blockWeeks,
        ?CarbonImmutable $nextYearStart = null,
    ): array {
        self::validateBlockWeeks($blockWeeks);

        $out = [];
        $cursor = $start->startOfDay();
        $last = count($blockWeeks);
        $positions = array_values($blockWeeks);

        foreach ($positions as $index => $weeks) {
            $position = $index + 1;
            $blockStart = $cursor;

            if ($position === $last && $nextYearStart !== null) {
                $blockEnd = $nextYearStart->startOfDay()->subDay();

                if ($blockEnd->lessThan($blockStart)) {
                    throw new InvalidArgumentException(
                        "The following year's start date ({$nextYearStart->format(Calendar::YMD)}) "
                        .'leaves no room for the final block, which would otherwise start '
                        ."{$blockStart->format(Calendar::YMD)}."
                    );
                }
            } else {
                $blockEnd = $blockStart->addWeeks($weeks)->subDay();
            }

            $out[] = [
                'position' => $position,
                'label' => "Block {$position}",
                'starts_on' => $blockStart->format(Calendar::YMD),
                'ends_on' => $blockEnd->format(Calendar::YMD),
                'kind' => Period::WEEK_BLOCK,
            ];

            $cursor = $blockEnd->addDay();
        }

        return $out;
    }

    /**
     * @param  list<array{starts_on:string,ends_on:string}>  $generated
     * @return list<string> human-readable warnings; EMPTY is the good case
     *
     * A gap or an overlap against the ADJACENT, ALREADY-PERSISTED academic year is reported,
     * never rejected — the department sets its own start dates (reconnaissance finding 7).
     */
    public static function warningsAgainstNeighbours(
        array $generated,
        ?Period $previousYearLast,
        ?Period $nextYearFirst,
    ): array {
        if ($generated === []) {
            return [];
        }

        $warnings = [];
        $firstStart = $generated[0]['starts_on'];
        $lastEnd = $generated[count($generated) - 1]['ends_on'];

        if ($previousYearLast !== null) {
            $prevEnd = $previousYearLast->ends_on->format(Calendar::YMD);
            $dayAfterPrevEnd = Calendar::addDays($prevEnd, 1)->format(Calendar::YMD);

            if ($firstStart <= $prevEnd) {
                $warnings[] = "This year's first period starts {$firstStart}, on or before the "
                    ."previous year's last period (\"{$previousYearLast->label}\") ends {$prevEnd}.";
            } elseif ($firstStart !== $dayAfterPrevEnd) {
                $warnings[] = "There is a gap between the previous year's last period "
                    ."(\"{$previousYearLast->label}\", ending {$prevEnd}) and this year's first "
                    ."period (starting {$firstStart}).";
            }
        }

        if ($nextYearFirst !== null) {
            $nextStart = $nextYearFirst->starts_on->format(Calendar::YMD);
            $dayAfterLastEnd = Calendar::addDays($lastEnd, 1)->format(Calendar::YMD);

            if ($nextStart <= $lastEnd) {
                $warnings[] = "The next year's first period (\"{$nextYearFirst->label}\") starts "
                    ."{$nextStart}, on or before this year's last period ends {$lastEnd}.";
            } elseif ($nextStart !== $dayAfterLastEnd) {
                $warnings[] = "There is a gap between this year's last period (ending {$lastEnd}) "
                    ."and the next year's first period (\"{$nextYearFirst->label}\", starting "
                    ."{$nextStart}).";
            }
        }

        return $warnings;
    }

    /** @param  list<int>  $blockWeeks */
    private static function validateBlockWeeks(array $blockWeeks): void
    {
        if ($blockWeeks === []) {
            throw new InvalidArgumentException('weekBlocks() requires at least one block length.');
        }

        if (count($blockWeeks) > self::MAX_BLOCKS) {
            throw new InvalidArgumentException(
                'weekBlocks() received '.count($blockWeeks).' blocks; the maximum is '.self::MAX_BLOCKS.'.'
            );
        }

        foreach ($blockWeeks as $weeks) {
            if (! is_int($weeks) || $weeks < self::MIN_BLOCK_WEEKS || $weeks > self::MAX_BLOCK_WEEKS) {
                throw new InvalidArgumentException(
                    "Block length {$weeks} weeks is outside the allowed range of "
                    .self::MIN_BLOCK_WEEKS.'-'.self::MAX_BLOCK_WEEKS.' weeks.'
                );
            }
        }
    }
}
