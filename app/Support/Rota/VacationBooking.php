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
        // The week is the DEPARTMENT's week, never a hardcoded Sunday or Monday — the department's
        // configuration decides, not a constant.
        if ($granularity === Vacation::GRANULARITY_WEEK) {
            $fromYmd = Calendar::weekOf($fromYmd)['starts_on'];
            $toYmd = Calendar::weekOf($toYmd)['ends_on'];
        }

        return Vacation::create([
            'institution_id' => $person->institution_id,
            'person_id' => $person->getKey(),
            'starts_on' => $fromYmd,
            'ends_on' => $toYmd,
            'granularity' => $granularity,
            'source' => $source,
        ]);
    }

    public static function cancel(Vacation $vacation): void
    {
        $vacation->delete();
    }
}
