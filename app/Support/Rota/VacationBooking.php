<?php

namespace App\Support\Rota;

use App\Models\Person;
use App\Models\Vacation;
use App\Support\Calendar;

/**
 * The ONE writer of `vacations` (Munawib AR-05/MR-03). `RotaWritersAreSingularTest` proves it.
 *
 * `book()` snaps a `week`-granularity booking to the DEPARTMENT's own week
 * (`Calendar::weekOf()`, Task 2) — derived from `weekend_days`, never a hardcoded Sunday or
 * Monday. Munawib AR-05 specifies a `'week'` granularity and never says what a week is; ST-01
 * makes the weekend department configuration. This is where the two are reconciled: the same rule
 * applies whether the leave is typed on screen or arrives through P1d-2's import, so a snap is
 * never silent in one path and not the other.
 *
 * There is no `restore()`: Decision E makes a cancel a real delete, with the hash-chained
 * `audit_log` as the history.
 */
final class VacationBooking
{
    public static function book(
        Person $person,
        string $fromYmd,
        string $toYmd,
        string $granularity,
        string $source = Vacation::SOURCE_MANUAL,
    ): Vacation {
        $snapped = self::snap($fromYmd, $toYmd, $granularity);

        return Vacation::create([
            'institution_id' => $person->institution_id,
            'person_id' => $person->getKey(),
            'starts_on' => $snapped['starts_on'],
            'ends_on' => $snapped['ends_on'],
            'granularity' => $granularity,
            'source' => $source,
        ]);
    }

    /**
     * The bounds a booking of this granularity actually stores — extracted from `book()` and
     * called BY `book()`, so owner decision 3's *"the same code path as the screen"* (2026-08-10)
     * is literally true rather than a claim about two similar-looking rules.
     *
     * `App\Support\Rota\RotaImport` calls it twice for two different purposes: to DISPLAY the
     * adjustment on a preview (an operator must see that their week got wider before they commit
     * it) and to decide whether a leave row already exists, which is a comparison against the
     * SNAPPED bounds and would otherwise miss every duplicated week.
     *
     * It writes nothing, so `RotaWritersAreSingularTest` is unaffected by its existence.
     *
     * The week is the DEPARTMENT's week (`Calendar::weekOf()`, derived from `weekend_days`), never
     * a hardcoded Sunday or Monday: Munawib AR-05 specifies a `week` granularity and never says
     * what a week is, and ST-01 makes the weekend department configuration. This is where the two
     * are reconciled — once.
     *
     * Snapping is IDEMPOTENT: the week containing a week's own first day is that week, so an
     * exported week booking re-imports onto the bounds it already has rather than creeping wider
     * on every round trip.
     *
     * @return array{starts_on: string, ends_on: string}
     */
    public static function snap(string $fromYmd, string $toYmd, string $granularity): array
    {
        if ($granularity !== Vacation::GRANULARITY_WEEK) {
            return ['starts_on' => $fromYmd, 'ends_on' => $toYmd];
        }

        return [
            'starts_on' => Calendar::weekOf($fromYmd)['starts_on'],
            'ends_on' => Calendar::weekOf($toYmd)['ends_on'],
        ];
    }

    public static function cancel(Vacation $vacation): void
    {
        $vacation->delete();
    }
}
