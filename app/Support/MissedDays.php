<?php

namespace App\Support;

use App\Models\Handover;
use App\Models\HandoverSignoff;

/**
 * Spec §10.3 — the missed-days computation, the system's ONLY aggregate.
 *
 * A calendar day is MISSED when the unit has no SIGNED sign-off for that date
 * (`signed_off_at` present). The expansion distinguishes:
 *   - `no_sheet`  — no handover rows exist for the day at all (the day was forgotten);
 *   - `unsigned`  — a sheet exists but was never signed (incl. days reopened and left open).
 *
 * Output is counts and dates ONLY — nothing here touches patient fields.
 *
 * Owner decision 6 (2026-08-08): the denominator is UNCHANGED by the calendar module. EVERY
 * calendar day counts, including weekends — Calendar::isWeekend()/dayType() are never consulted
 * here. Making this weekend/holiday-aware would silently alter every historical compliance
 * figure the system has ever produced; that is a data-meaning change requiring its own owner
 * ruling, deliberately not made here.
 * See tests/Feature/Calendar/ConverterAbsorptionTest.php's denominator test.
 */
final class MissedDays
{
    /**
     * @return array{total_days: int, missed: list<array{date: string, kind: string}>}
     */
    public static function forRange(int $unitId, string $from, string $to): array
    {
        $start = Calendar::parse($from);
        // Future days have not happened, so they cannot be missed.
        $end = Calendar::parse($to)->min(Calendar::today());

        if ($end->lessThan($start)) {
            return ['total_days' => 0, 'missed' => []];
        }

        $signedDates = HandoverSignoff::query()
            ->where('unit_id', $unitId)
            ->whereNotNull('signed_off_at')
            ->whereDate('handover_date', '>=', $start->format(Calendar::YMD))
            ->whereDate('handover_date', '<=', $end->format(Calendar::YMD))
            ->pluck('handover_date')
            ->map(fn ($d): string => Calendar::ymd($d))
            ->flip();

        $sheetDates = Handover::query()
            ->where('unit_id', $unitId)
            ->whereDate('handover_date', '>=', $start->format(Calendar::YMD))
            ->whereDate('handover_date', '<=', $end->format(Calendar::YMD))
            ->distinct()
            ->pluck('handover_date')
            ->map(fn ($d): string => Calendar::ymd($d))
            ->flip();

        $missed = [];
        $total = 0;

        // Every calendar day in range, unconditionally — no weekend/holiday filtering.
        foreach (Calendar::datesBetween($start, $end) as $date) {
            $total++;

            if (isset($signedDates[$date])) {
                continue;
            }

            $missed[] = [
                'date' => $date,
                'kind' => isset($sheetDates[$date]) ? 'unsigned' : 'no_sheet',
            ];
        }

        return ['total_days' => $total, 'missed' => $missed];
    }
}
