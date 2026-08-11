<?php

/*
 * Calendar vocabulary. Munawib AR-07: strings are externalized from launch so a future locale is
 * translation work, not a rewrite. English-only at launch.
 *
 * BOTH tables below are read ONLY by `App\Support\Calendar` (AR-08 — nothing else labels a date),
 * and both are pinned by `tests/fixtures/calendar/golden.json`, the framework-free contract P2's
 * `packages/engine` mirror asserts against its own implementation.
 */
return [
    'hijri_months' => [
        1 => 'Muharram',   2 => 'Safar',       3 => 'Rabi al-Awwal', 4 => 'Rabi al-Thani',
        5 => 'Jumada al-Ula', 6 => 'Jumada al-Akhirah', 7 => 'Rajab', 8 => "Sha'ban",
        9 => 'Ramadan',   10 => 'Shawwal',    11 => 'Dhu al-Qidah', 12 => 'Dhu al-Hijjah',
    ],

    /*
     * ISO-8601 weekday names, keyed Monday = 1 … Sunday = 7 — the SAME numbering as
     * `Calendar::weekendDays()`, `::weekStartIsoDay()` and `clinics.weekday`. Carbon's `dayOfWeek`
     * (Sunday = 0) is a second numbering scheme and is never a key here; `Calendar::weekdayLabel()`
     * throws on 0 rather than quietly answering for a day the caller did not mean.
     *
     * `label` and `short` sit in ONE entry per day deliberately: two parallel arrays are two
     * lengths that can drift, and a translator needs to see the pair together — an abbreviation is
     * not always the first three characters of a name in another language.
     */
    'weekdays' => [
        1 => ['label' => 'Monday',    'short' => 'Mon'],
        2 => ['label' => 'Tuesday',   'short' => 'Tue'],
        3 => ['label' => 'Wednesday', 'short' => 'Wed'],
        4 => ['label' => 'Thursday',  'short' => 'Thu'],
        5 => ['label' => 'Friday',    'short' => 'Fri'],
        6 => ['label' => 'Saturday',  'short' => 'Sat'],
        7 => ['label' => 'Sunday',    'short' => 'Sun'],
    ],
];
