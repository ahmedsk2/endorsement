<?php

namespace App\Support;

use App\Models\Institution;
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

    private static ?array $settings = null;

    /** Tests and long-running processes must be able to drop the memoized settings. */
    public static function flush(): void
    {
        self::$settings = null;
    }

    public static function timezone(): string
    {
        return (string) config('app.timezone');
    }

    public static function today(): CarbonImmutable
    {
        return CarbonImmutable::now(self::timezone())->startOfDay();
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
     * The one shape every screen renders a date as (UX-04).
     *
     * @return array{date:string, hijri:string, weekend:bool}
     */
    public static function label(DateTimeInterface|string $date): array
    {
        $day = self::coerce($date);

        return [
            'date' => $day->format(self::YMD),
            'hijri' => self::hijriLabel($day),
            'weekend' => self::isWeekend($day),
        ];
    }

    /** @return list<int> ISO-8601 weekday numbers, Mon=1 ... Sun=7. */
    public static function weekendDays(): array
    {
        return self::settings()['weekend_days'];
    }

    public static function isWeekend(DateTimeInterface|string $date): bool
    {
        return in_array((int) self::coerce($date)->isoWeekday(), self::weekendDays(), true);
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
            'weekend_days' => array_map('intval', $institution?->weekend_days ?: self::DEFAULT_WEEKEND),
            'period_type' => (string) ($institution?->period_type ?? Institution::PERIOD_WEEK_BLOCKS),
            'block_weeks' => array_map('intval', $institution?->block_weeks ?: []),
            'academic_year_start' => $institution?->academic_year_start?->format(self::YMD),
        ];
    }
}
