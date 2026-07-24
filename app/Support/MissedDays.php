<?php

namespace App\Support;

use App\Models\Handover;
use App\Models\HandoverSignoff;
use Illuminate\Support\Carbon;

/**
 * Spec §10.3 — the missed-days computation, the system's ONLY aggregate.
 *
 * A calendar day is MISSED when the unit has no SIGNED sign-off for that date
 * (`signed_off_at` present). The expansion distinguishes:
 *   - `no_sheet`  — no handover rows exist for the day at all (the day was forgotten);
 *   - `unsigned`  — a sheet exists but was never signed (incl. days reopened and left open).
 *
 * Output is counts and dates ONLY — nothing here touches patient fields.
 */
final class MissedDays
{
    /**
     * @return array{total_days: int, missed: list<array{date: string, kind: string}>}
     */
    public static function forRange(int $unitId, string $from, string $to): array
    {
        $start = Carbon::parse($from)->startOfDay();
        // Future days have not happened, so they cannot be missed.
        $end = Carbon::parse($to)->startOfDay()->min(Carbon::today());

        if ($end->lessThan($start)) {
            return ['total_days' => 0, 'missed' => []];
        }

        $signedDates = HandoverSignoff::query()
            ->where('unit_id', $unitId)
            ->whereNotNull('signed_off_at')
            ->whereDate('handover_date', '>=', $start->format('Y-m-d'))
            ->whereDate('handover_date', '<=', $end->format('Y-m-d'))
            ->pluck('handover_date')
            ->map(fn ($d): string => Carbon::parse($d)->format('Y-m-d'))
            ->flip();

        $sheetDates = Handover::query()
            ->where('unit_id', $unitId)
            ->whereDate('handover_date', '>=', $start->format('Y-m-d'))
            ->whereDate('handover_date', '<=', $end->format('Y-m-d'))
            ->distinct()
            ->pluck('handover_date')
            ->map(fn ($d): string => Carbon::parse($d)->format('Y-m-d'))
            ->flip();

        $missed = [];
        $total = 0;

        for ($day = $start->copy(); $day->lessThanOrEqualTo($end); $day->addDay()) {
            $date = $day->format('Y-m-d');
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
