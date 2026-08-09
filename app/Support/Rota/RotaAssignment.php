<?php

namespace App\Support\Rota;

use App\Models\MasterRotaAssignment;
use App\Models\Period;
use App\Models\Person;
use App\Models\Unit;
use App\Support\Calendar;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * The ONE writer of `master_rota_assignments` (Munawib MR-02). `RotaWritersAreSingularTest` proves
 * it, the same way `PersonLevelsHaveOneWriterTest` proves `LevelAssignment` is for
 * `person_levels`.
 *
 * Every method REFUSES BEFORE IT WRITES. `split()` in particular validates the whole span set —
 * containment, ordering, mutual overlap — before opening the transaction that deletes what is
 * already there, so a rejected split never destroys a good one. That is `UnitMerge`'s
 * pre-check-and-refuse discipline and `AccessControlController::updateRoles()`' "authorize the
 * whole set before any write", applied here.
 *
 * `split()` REPLACES, never merges. Merging a partial span set into an existing one is where an
 * overlap sneaks past a check that only looked at what was submitted.
 *
 * There is no `restore()` and no undo: Decision E makes a clear a real delete, with the
 * hash-chained audit_log as the history. UX-03's undo/redo arrives with the Stage-2 workbench.
 */
final class RotaAssignment
{
    public const ASSIGNED = 'assigned';

    public const UNCHANGED = 'unchanged';

    public const REPLACED = 'replaced';

    public const CLEARED = 'cleared';

    public const NOTHING_TO_CLEAR = 'nothing_to_clear';

    /** One unit for the whole period. The degenerate split: exactly one row, period-wide. */
    public static function set(Person $person, Period $period, Unit $unit): string
    {
        $existing = self::spansFor($person, $period);

        if (count($existing) === 1
            && (int) $existing[0]->unit_id === (int) $unit->getKey()
            && $existing[0]->starts_on->format(Calendar::YMD) === $period->starts_on->format(Calendar::YMD)
            && $existing[0]->ends_on->format(Calendar::YMD) === $period->ends_on->format(Calendar::YMD)) {
            return self::UNCHANGED;
        }

        $had = $existing !== [];

        DB::transaction(function () use ($person, $period, $unit): void {
            self::deleteSpans($person, $period);

            MasterRotaAssignment::create([
                'institution_id' => $person->institution_id,
                'person_id' => $person->getKey(),
                'period_id' => $period->getKey(),
                'unit_id' => $unit->getKey(),
                'starts_on' => $period->starts_on->format(Calendar::YMD),
                'ends_on' => $period->ends_on->format(Calendar::YMD),
            ]);
        });

        return $had ? self::REPLACED : self::ASSIGNED;
    }

    /**
     * MR-02's date-bounded sub-assignments. Replaces every span this person holds in this period.
     *
     * Gaps between spans are ACCEPTED (owner decision 3): a mid-block joiner and a half-planned
     * year are both real states, and the grid renders uncovered days rather than refusing them.
     * Overlaps are refused here, before the transaction, and again by the model's own guard.
     *
     * @param  list<array{unit_id:int, starts_on:string, ends_on:string}>  $spans
     *
     * @throws InvalidArgumentException when the set is empty, escapes the period, or self-overlaps
     */
    public static function split(Person $person, Period $period, array $spans): string
    {
        if ($spans === []) {
            throw new InvalidArgumentException('A split needs at least one span. To remove an assignment, clear it.');
        }

        $periodFrom = $period->starts_on->format(Calendar::YMD);
        $periodTo = $period->ends_on->format(Calendar::YMD);

        $normalised = [];

        foreach ($spans as $span) {
            $from = Calendar::ymd($span['starts_on']);   // throws on anything but Y-m-d
            $to = Calendar::ymd($span['ends_on']);

            if ($to < $from) {
                throw new InvalidArgumentException("A span ends ({$to}) before it starts ({$from}).");
            }

            if ($from < $periodFrom || $to > $periodTo) {
                throw new InvalidArgumentException(
                    "A span ({$from}..{$to}) falls outside \"{$period->label}\" ({$periodFrom}..{$periodTo})."
                );
            }

            $normalised[] = ['unit_id' => (int) $span['unit_id'], 'starts_on' => $from, 'ends_on' => $to];
        }

        usort($normalised, fn (array $a, array $b): int => $a['starts_on'] <=> $b['starts_on']);

        for ($i = 1; $i < count($normalised); $i++) {
            if ($normalised[$i]['starts_on'] <= $normalised[$i - 1]['ends_on']) {
                throw new InvalidArgumentException(
                    'Two spans in this split cover the same day. One person on two units on one day '
                    .'is a state the grid cannot render and the call roster cannot resolve.'
                );
            }
        }

        $had = self::spansFor($person, $period) !== [];

        DB::transaction(function () use ($person, $period, $normalised): void {
            self::deleteSpans($person, $period);

            foreach ($normalised as $span) {
                MasterRotaAssignment::create([
                    'institution_id' => $person->institution_id,
                    'person_id' => $person->getKey(),
                    'period_id' => $period->getKey(),
                    'unit_id' => $span['unit_id'],
                    'starts_on' => $span['starts_on'],
                    'ends_on' => $span['ends_on'],
                ]);
            }
        });

        return $had ? self::REPLACED : self::ASSIGNED;
    }

    public static function clear(Person $person, Period $period): string
    {
        if (self::spansFor($person, $period) === []) {
            return self::NOTHING_TO_CLEAR;
        }

        DB::transaction(fn () => self::deleteSpans($person, $period));

        return self::CLEARED;
    }

    /** @return list<MasterRotaAssignment> */
    private static function spansFor(Person $person, Period $period): array
    {
        return MasterRotaAssignment::query()
            ->where('person_id', $person->getKey())
            ->where('period_id', $period->getKey())
            ->orderBy('starts_on')
            ->get()
            ->all();
    }

    private static function deleteSpans(Person $person, Period $period): void
    {
        MasterRotaAssignment::query()
            ->where('person_id', $person->getKey())
            ->where('period_id', $period->getKey())
            ->delete();
    }
}
