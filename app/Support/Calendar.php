<?php

namespace App\Support;

use App\Models\Holiday;
use App\Models\Institution;
use App\Models\Period;
use Carbon\CarbonImmutable;
use DateTimeInterface;
use DateTimeZone;
use IntlCalendar;
use InvalidArgumentException;

/**
 * Munawib AR-08: ALL date logic flows through this class, applying the instance timezone and
 * the department's hijriOffsetDays. Nothing outside it converts a date.
 *
 * WHAT THIS IS NOT:
 *
 *  - It is NOT input validation. Route dates stay regex-pinned (routes/web.php:43,50,...) and
 *    writes keep `date_format:Y-m-d`. `parse()` THROWS on anything that is not exactly Y-m-d,
 *    because `strtotime()` leniency accepted "+5 years" and created real backdated clinical
 *    rows (EndorsementController.php:551-554). Do not add a lenient sibling.
 *
 *  - It is NOT for audit canonicalization. `AuditChain::canonical()` v3 hashes the stored
 *    naive datetime BYTE-VERBATIM (AuditChain.php:65-67) precisely so no timezone can
 *    reinterpret history. Never route an audit value through here.
 *
 *  - It is NOT for `dob`. `App\Casts\EncryptedDateTime` holds PHI as ciphertext; it cannot be
 *    range-queried or sorted, and its getter can return a string marker.
 *
 * TIMEZONE lives on the INSTANCE (`APP_TIMEZONE`), not the department — owner decision 3,
 * 2026-08-08. HIJRI OFFSET lives on the department.
 */
final class Calendar
{
    public const YMD = 'Y-m-d';

    /** Umm al-Qura. ICU ships it; ext-intl is installed in the image and in CI. */
    private const HIJRI_LOCALE = 'en@calendar=islamic-umalqura';

    /** Defaults for a deployment whose institution row does not exist yet. */
    private const DEFAULT_WEEKEND = [5, 6];

    public const DAY_WEEKDAY = 'WD';

    public const DAY_WEEKEND = 'WE';

    public const DAY_HOLIDAY = 'HOL';

    private static ?array $settings = null;

    /** @var list<Holiday>|null */
    private static ?array $holidays = null;

    /** Tests and long-running processes must be able to drop the memoized settings. */
    public static function flush(): void
    {
        self::$settings = null;
        self::$holidays = null;
    }

    public static function timezone(): string
    {
        return (string) config('app.timezone');
    }

    public static function today(): CarbonImmutable
    {
        return CarbonImmutable::now(self::timezone())->startOfDay();
    }

    /** The current instant in the instance timezone — unlike today(), NOT truncated to midnight. */
    public static function now(): CarbonImmutable
    {
        return CarbonImmutable::now(self::timezone());
    }

    public static function todayYmd(): string
    {
        return self::today()->format(self::YMD);
    }

    /**
     * Y-m-d ONLY. Throws on anything else, including a well-formed-but-impossible date such
     * as 2026-02-30, which PHP would otherwise roll forward to 2026-03-02.
     */
    public static function parse(string $date): CarbonImmutable
    {
        $trimmed = trim($date);

        try {
            $parsed = CarbonImmutable::createFromFormat('!'.self::YMD, $trimmed, self::timezone());
        } catch (\Throwable) {
            $parsed = null;
        }

        if (! $parsed instanceof CarbonImmutable || $parsed->format(self::YMD) !== $trimmed) {
            throw new InvalidArgumentException(
                'Calendar::parse() accepts Y-m-d only; got "'.substr($trimmed, 0, 32).'". '
                .'Leniency here is what let "+5 years" create backdated clinical rows.'
            );
        }

        return $parsed;
    }

    public static function tryParse(?string $date): ?CarbonImmutable
    {
        if ($date === null || trim($date) === '') {
            return null;
        }

        try {
            return self::parse($date);
        } catch (InvalidArgumentException) {
            return null;
        }
    }

    public static function ymd(DateTimeInterface|CarbonImmutable|string $date): string
    {
        return self::coerce($date)->format(self::YMD);
    }

    public static function addDays(DateTimeInterface|string $date, int $days): CarbonImmutable
    {
        return self::coerce($date)->addDays($days);
    }

    /** @return list<string> inclusive both ends, empty when `$to` precedes `$from`. */
    public static function datesBetween(
        DateTimeInterface|string $from,
        DateTimeInterface|string $to,
    ): array {
        $start = self::coerce($from);
        $end = self::coerce($to);

        if ($end->lessThan($start)) {
            return [];
        }

        $out = [];

        for ($day = $start; $day->lessThanOrEqualTo($end); $day = $day->addDay()) {
            $out[] = $day->format(self::YMD);
        }

        return $out;
    }

    public static function hijriEnabled(): bool
    {
        return (bool) self::settings()['hijri_enabled'];
    }

    public static function hijriOffsetDays(): int
    {
        return (int) self::settings()['hijri_offset_days'];
    }

    /**
     * @return array{year:int, month:int, day:int}
     *
     * The offset is applied to the GREGORIAN instant before conversion, never by adjusting
     * the resulting Hijri day number. Decrementing the day number produces 1448-02-00 at a
     * month boundary; shifting the instant produces 1448-01-29, which is the real answer.
     */
    public static function hijri(DateTimeInterface|string $date): array
    {
        // Noon, so no timezone or transition edge can move the day under us. Riyadh has no
        // DST, but this module must be right for any instance timezone.
        $instant = self::coerce($date)->addDays(self::hijriOffsetDays())->setTime(12, 0);

        $calendar = IntlCalendar::createInstance(
            new DateTimeZone(self::timezone()),
            self::HIJRI_LOCALE,
        );
        $calendar->setTime($instant->getTimestamp() * 1000);

        return [
            'year' => (int) $calendar->get(IntlCalendar::FIELD_YEAR),
            'month' => (int) $calendar->get(IntlCalendar::FIELD_MONTH) + 1, // ICU months are 0-based
            'day' => (int) $calendar->get(IntlCalendar::FIELD_DAY_OF_MONTH),
        ];
    }

    /** e.g. "24 Safar 1448". Empty string when the department has Hijri display off. */
    public static function hijriLabel(DateTimeInterface|string $date): string
    {
        if (! self::hijriEnabled()) {
            return '';
        }

        $h = self::hijri($date);
        $months = (array) __('calendar.hijri_months');

        return $h['day'].' '.($months[$h['month']] ?? $h['month']).' '.$h['year'];
    }

    /**
     * The active holiday rules matching `$date` — usually zero, occasionally more than one
     * (a department could define an overlapping regional holiday alongside a national one).
     *
     * Walks BACKWARDS from `$date` up to `duration_days - 1` days, asking each candidate rule
     * whether it is ANCHORED there (`Holiday::anchoredOn()`). Duration is counted in GREGORIAN
     * days throughout, so a span defined in Hijri terms that crosses a Hijri month end (the run
     * into Eid al-Fitr, say) needs no special case — see `HolidayTest`'s month-end case.
     *
     * @return list<Holiday>
     */
    public static function holidaysOn(DateTimeInterface|string $date): array
    {
        return array_map(
            static fn (array $occurrence): Holiday => $occurrence['holiday'],
            self::holidayOccurrencesOn($date),
        );
    }

    /**
     * The same walk, plus the OWN-CALENDAR YEAR of the anchor the walk landed on.
     *
     * The walk is here rather than in `holidaysOn()` because the year is only knowable from the
     * ANCHOR, and the anchor is what that loop already computes and used to throw away. A caller
     * that re-derived it would be a second definition of where a span begins — and the two would
     * disagree only on a span crossing a year end, which is exactly the case the year exists to
     * distinguish. A four-day Gregorian rule anchored 30 December covers 2 January, and that day
     * belongs to the 2026 occurrence, not to 2027.
     *
     * `year` follows the RULE's own calendar (`holidays.year`'s stated contract, and P2 owner
     * decision W's `yearBasis: 'ruleCalendar'`): a Hijri rule's year is a Hijri year, resolved
     * through `hijri()` so the department's offset calibration applies here as everywhere else.
     *
     * @return list<array{holiday:Holiday, year:int}>
     */
    public static function holidayOccurrencesOn(DateTimeInterface|string $date): array
    {
        $day = self::coerce($date);
        $matches = [];

        foreach (self::activeHolidays() as $holiday) {
            for ($back = 0; $back < $holiday->duration_days; $back++) {
                $anchor = $day->subDays($back);

                if ($holiday->anchoredOn($anchor)) {
                    $matches[] = [
                        'holiday' => $holiday,
                        'year' => $holiday->calendar === Holiday::HIJRI
                            ? (int) self::hijri($anchor)['year']
                            : (int) $anchor->format('Y'),
                    ];

                    break;
                }
            }
        }

        return $matches;
    }

    public static function isHoliday(DateTimeInterface|string $date): bool
    {
        return self::holidaysOn($date) !== [];
    }

    /**
     * SL-03's day type. HOLIDAY WINS OVER WEEKEND — a coverage template that asks for holiday
     * staffing must get it on a holiday that happens to fall on a Friday, not weekend staffing.
     *
     * NEVER consulted by `App\Support\MissedDays` (owner decision 6) — the compliance
     * denominator counts every calendar day exactly as it always has, and routing it through
     * day-type knowledge the system did not previously have would silently change every
     * historical compliance figure. See `MissedDays`' own docblock and
     * `HolidayTest::test_missed_days_denominator_is_unaffected_by_a_holiday`.
     */
    public static function dayType(DateTimeInterface|string $date): string
    {
        return self::dayFacts($date)['day_type'];
    }

    /**
     * EVERY per-date fact, resolved in ONE pass — the shape a per-day vector needs.
     *
     * The rule that holiday beats weekend now lives HERE and nowhere else. It used to live in
     * `dayType()` and, separately, inline in `label()`; two copies of one three-branch decision is
     * `AuditChain::canonical()`'s defect in miniature, and this method is what removes the second
     * rather than adding a third.
     *
     * IT ALSO EXISTS FOR COST, and that is measured rather than assumed (P2 Task 1 finding 6):
     * `holidaysOn()` walks backwards `duration_days` per rule, and a Hijri rule builds an
     * `IntlCalendar` per probe — 30 consecutive days cost roughly 24 ms with four holidays
     * configured, and the same 30 days re-asked nine times cost roughly 203 ms. A caller wanting
     * both the day type and the holidays of a date would otherwise walk twice per date, and a year
     * of dates twice over is the re-ask factor that measurement warns about.
     *
     * @return array{iso_weekday:int, day_type:string, holidays:list<array{holiday:Holiday, year:int}>}
     */
    public static function dayFacts(DateTimeInterface|string $date): array
    {
        $day = self::coerce($date);
        $holidays = self::holidayOccurrencesOn($day);

        return [
            'iso_weekday' => self::isoWeekday($day),
            'day_type' => $holidays !== []
                ? self::DAY_HOLIDAY
                : (self::isWeekend($day) ? self::DAY_WEEKEND : self::DAY_WEEKDAY),
            'holidays' => $holidays,
        ];
    }

    /**
     * ISO-8601 weekday, Mon=1 … Sun=7.
     *
     * P2 Task 1 finding 21: the value was reachable only from INSIDE `isWeekend()` and `weekOf()`,
     * while `golden.json` has contracted `iso_weekday` per date since version 2 and the engine's
     * day vector carries one. It is a public surface here rather than a helper somewhere else
     * because `CalendarIsTheOnlyConverterTest` forbids constructing a Carbon outside this module —
     * so "the weekday of a date" has exactly one place it can honestly be answered.
     */
    public static function isoWeekday(DateTimeInterface|string $date): int
    {
        return (int) self::coerce($date)->isoWeekday();
    }

    /**
     * The one shape every screen renders a date as (UX-04).
     *
     * @return array{date:string, hijri:string, weekend:bool, holiday:?string, day_type:string}
     */
    public static function label(DateTimeInterface|string $date): array
    {
        $day = self::coerce($date);
        $facts = self::dayFacts($day);

        return [
            'date' => $day->format(self::YMD),
            'hijri' => self::hijriLabel($day),
            'weekend' => self::isWeekend($day),
            // The first matching rule's name; a screen needs one label, not a list.
            'holiday' => $facts['holidays'] === [] ? null : $facts['holidays'][0]['holiday']->name,
            // Through `dayFacts()`, not re-derived: this line used to carry its own copy of
            // "holiday beats weekend", three methods away from `dayType()`'s.
            'day_type' => $facts['day_type'],
        ];
    }

    /** @return list<int> ISO-8601 weekday numbers, Mon=1 ... Sun=7. */
    public static function weekendDays(): array
    {
        return self::settings()['weekend_days'];
    }

    public static function isWeekend(DateTimeInterface|string $date): bool
    {
        return in_array(self::isoWeekday($date), self::weekendDays(), true);
    }

    /**
     * The ISO weekday (Mon=1 … Sun=7) a week begins on for THIS department.
     *
     * Munawib AR-05 gives vacations a `granularity: 'week'` and MR-07 reports availability "each
     * week", but the spec never says what a week is — while ST-01 makes weekend days department
     * configuration. Two departments with different weekends would otherwise snap the same leave
     * to different dates.
     *
     * The rule: the week begins the day after the LAST configured weekend day, wrapping. Friday
     * and Saturday off (the QCH default, [5, 6]) gives a Sunday start; a Saturday–Sunday weekend
     * gives a Monday start. An empty weekend list falls back to Monday rather than producing no
     * answer.
     */
    public static function weekStartIsoDay(): int
    {
        $weekend = self::weekendDays();

        if ($weekend === []) {
            return 1;
        }

        sort($weekend);

        return (int) (max($weekend) % 7) + 1;
    }

    /**
     * The English name of an ISO-8601 weekday, Monday = 1 … Sunday = 7 (P1e Decision A).
     *
     * A weekday NUMBER is not a date — it is a recurrence-rule component (`clinics.weekday`), and
     * storing one converts nothing. Its NAME is this module's, though, for the same reason
     * `hijriLabel()` is: a screen that writes its own day names is the second converter AR-08
     * exists to forbid, and it would drift from `weekendDays()`'s numbering the first time
     * somebody reached for Carbon's `dayOfWeek` (Sunday = 0) instead.
     *
     * NO `IntlDateFormatter`, no `IntlCalendar`, no date construction: a weekday name is a
     * vocabulary lookup, and building a date to ask what it is called would put an unnecessary
     * converter inside the module whose whole job is to be the only one.
     *
     * @throws InvalidArgumentException on anything outside 1..7 — including 0, which is exactly
     *                                  what Carbon's `dayOfWeek` calls Sunday.
     */
    public static function weekdayLabel(int $iso): string
    {
        return self::weekdayStrings($iso)['label'];
    }

    /**
     * The department's week, in the order IT runs, one entry per day (P1e Decision A).
     *
     * Rotated to begin at `weekStartIsoDay()`, which is itself derived from `weekend_days` — there
     * is no `institutions.week_start` column and there never was. `weekend` is read from
     * `weekendDays()`, never recomputed from the rotation, so there is one definition of "is this
     * a weekend day" and not two.
     *
     * NOTHING STORED DEPENDS ON THE WEEK START. The ISO integer in `clinics.weekday` is absolute;
     * only presentation rotates. A department that changes its weekend re-orders every clinic map
     * and every weekday `<select>` in the instance immediately, with no stored value changing and
     * no data migration — `WeekdayVocabularyTest::
     * test_changing_the_weekend_reorders_the_columns_with_no_stored_value_changing` is that
     * property, and `golden.json`'s `weekday_columns` block is it in the shared contract.
     *
     * Deliberately NOT memoised: it is a rotation over a seven-entry table and derives everything
     * from `weekendDays()`, which is already inside the memo `flush()` clears. A second static
     * here would be a second thing to forget to flush.
     *
     * @return list<array{iso:int, label:string, short:string, weekend:bool}>
     */
    public static function weekdayColumns(): array
    {
        $start = self::weekStartIsoDay();
        $weekend = self::weekendDays();

        $columns = [];

        for ($offset = 0; $offset < 7; $offset++) {
            $iso = (($start - 1 + $offset) % 7) + 1;
            $strings = self::weekdayStrings($iso);

            $columns[] = [
                'iso' => $iso,
                'label' => $strings['label'],
                'short' => $strings['short'],
                'weekend' => in_array($iso, $weekend, true),
            ];
        }

        return $columns;
    }

    /**
     * @return array{label:string, short:string}
     *
     * One range check for both public entry points, and it doubles as the guard against a broken
     * or partially translated vocabulary table — so there is no unreachable defensive branch here
     * that no test could ever exercise.
     */
    private static function weekdayStrings(int $iso): array
    {
        $entry = ((array) __('calendar.weekdays'))[$iso] ?? null;

        if (! is_array($entry) || ! isset($entry['label'], $entry['short'])) {
            throw new InvalidArgumentException(
                'Calendar::weekdayLabel() takes an ISO-8601 weekday with an entry in '
                .'lang/*/calendar.php — Monday = 1 ... Sunday = 7; got '.$iso.'. '
                ."Carbon's dayOfWeek (Sunday = 0) is a second numbering scheme and is never what "
                .'this module means.'
            );
        }

        return ['label' => (string) $entry['label'], 'short' => (string) $entry['short']];
    }

    /**
     * The week containing a date, BOTH BOUNDS INCLUSIVE — the same idiom `Person::levelAt()` and
     * `Period::contains()` share. Labels are dual-dated (UX-04) because the client performs no
     * date formatting at all (Decision A, P1a).
     *
     * @return array{starts_on:string, ends_on:string, starts_label:array<string,mixed>, ends_label:array<string,mixed>}
     */
    public static function weekOf(DateTimeInterface|string $date): array
    {
        $day = self::coerce($date);
        $start = self::weekStartIsoDay();

        // How many days back to the most recent $start-weekday, 0..6.
        $back = (self::isoWeekday($day) - $start + 7) % 7;

        $from = $day->subDays($back);
        $to = $from->addDays(6);

        return [
            'starts_on' => $from->format(self::YMD),
            'ends_on' => $to->format(self::YMD),
            'starts_label' => self::label($from),
            'ends_label' => self::label($to),
        ];
    }

    /**
     * Every week INTERSECTING a range, in order. `starts_on`/`ends_on` are the true week bounds;
     * `clipped_*` are those bounds trimmed to the range, which is what a per-period week strip
     * (MR-07) actually renders — a period rarely begins on a week boundary.
     *
     * Capped, deliberately: an unbounded loop over a range built from a mistyped year is how a
     * screen becomes a memory exhaustion. 550 days is comfortably more than the longest academic
     * year this system generates (owner decision 4: 365 or 366 days, block 13 absorbing the
     * remainder).
     *
     * @return list<array{starts_on:string, ends_on:string, clipped_starts_on:string, clipped_ends_on:string, starts_label:array<string,mixed>, ends_label:array<string,mixed>}>
     */
    public static function weeksIn(DateTimeInterface|string $from, DateTimeInterface|string $to): array
    {
        $rangeStart = self::coerce($from);
        $rangeEnd = self::coerce($to);

        if ($rangeEnd->lessThan($rangeStart)) {
            throw new InvalidArgumentException('A week range ends before it starts.');
        }

        if ($rangeStart->diffInDays($rangeEnd) > 550) {
            throw new InvalidArgumentException('A week range may not exceed 550 days.');
        }

        $out = [];
        $cursor = self::parse(self::weekOf($rangeStart)['starts_on']);
        $endYmd = $rangeEnd->format(self::YMD);

        while ($cursor->format(self::YMD) <= $endYmd) {
            $week = self::weekOf($cursor);

            $out[] = $week + [
                'clipped_starts_on' => max($week['starts_on'], $rangeStart->format(self::YMD)),
                'clipped_ends_on' => min($week['ends_on'], $endYmd),
            ];

            $cursor = $cursor->addDays(7);
        }

        return $out;
    }

    /** MR-01: 'months' or 'week_blocks' (`Institution::PERIOD_MONTHS`/`PERIOD_WEEK_BLOCKS`). */
    public static function periodType(): string
    {
        return self::settings()['period_type'];
    }

    /** @return list<int> block lengths in weeks, in order — see `PeriodGenerator::weekBlocks()`. */
    public static function blockWeeks(): array
    {
        return self::settings()['block_weeks'];
    }

    /** The first day of the CURRENT academic year (department-set); null until configured. */
    public static function academicYearStart(): ?CarbonImmutable
    {
        $start = self::settings()['academic_year_start'];

        return $start === null ? null : self::parse($start);
    }

    /**
     * The period a date falls in, both bounds INCLUSIVE — the same idiom `Person::levelAt()`
     * uses. Null when the date falls outside every generated period.
     */
    public static function periodFor(DateTimeInterface|string $date): ?Period
    {
        $day = self::coerce($date)->format(self::YMD);

        return Period::query()
            ->whereDate('starts_on', '<=', $day)
            ->whereDate('ends_on', '>=', $day)
            ->first();
    }

    /** @return list<Period> every period for one academic year, ordered by start date. */
    public static function periodsForYear(string $academicYear): array
    {
        return Period::query()
            ->where('academic_year', $academicYear)
            ->orderBy('starts_on')
            ->get()
            ->all();
    }

    private static function coerce(DateTimeInterface|CarbonImmutable|string $date): CarbonImmutable
    {
        if (is_string($date)) {
            return self::parse($date);
        }

        return CarbonImmutable::instance($date)
            ->setTimezone(self::timezone())
            ->startOfDay();
    }

    /**
     * Memoized per process, cleared by flush(). D11 — never filtered by `institution_id`
     * (provenance/grouping only, not a query boundary; see `periods`' equivalent query and
     * `InstitutionProvenanceTest::test_no_query_filters_on_institution_id`).
     *
     * @return list<Holiday>
     */
    private static function activeHolidays(): array
    {
        if (self::$holidays !== null) {
            return self::$holidays;
        }

        return self::$holidays = Holiday::query()->where('active', true)->get()->all();
    }

    /**
     * Memoized per process. `Institution::current()` returns null on a zero- or
     * multi-institution deployment (Institution.php) — that is NOT an error here.
     * RefreshDatabase runs every test against an empty institutions table, and a calendar that
     * threw there would take 600+ unrelated tests with it.
     *
     * @return array{hijri_enabled:bool, hijri_offset_days:int, weekend_days:list<int>,
     *               period_type:string, block_weeks:list<int>, academic_year_start:?string}
     */
    private static function settings(): array
    {
        if (self::$settings !== null) {
            return self::$settings;
        }

        $institution = Institution::current();

        return self::$settings = [
            'hijri_enabled' => (bool) ($institution?->hijri_enabled ?? true),
            'hijri_offset_days' => (int) ($institution?->hijri_offset_days ?? 0),
            // `??`, not `?:` — an institution row's weekend_days is NULLABLE, but once a row
            // exists its value (even `[]`) is a real, explicit configuration, not "unset". The
            // admin form already refuses an empty list (`CalendarSettingsTest::
            // test_weekend_days_rejects_an_empty_list`), so `[]` never reaches here through
            // normal use — but `weekStartIsoDay()`'s own empty-weekend fallback (P1d Task 2)
            // needs `weekendDays()` to actually be able to report `[]` rather than have it
            // silently rewritten to the default first, or that defensive branch is dead code
            // no test can ever reach.
            'weekend_days' => $institution?->weekend_days === null
                ? self::DEFAULT_WEEKEND
                : array_map('intval', $institution->weekend_days),
            'period_type' => (string) ($institution?->period_type ?? Institution::PERIOD_WEEK_BLOCKS),
            'block_weeks' => array_map('intval', $institution?->block_weeks ?: []),
            'academic_year_start' => $institution?->academic_year_start?->format(self::YMD),
        ];
    }
}
