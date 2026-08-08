<?php

namespace App\Models;

use App\Support\Calendar;
use Carbon\CarbonImmutable;
use Database\Factories\HolidayFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * Munawib §30 — a recurring (or one-off) holiday rule, in EITHER calendar. The CRUD screen is
 * P1b; `App\Support\Calendar::holidaysOn()`/`dayType()` are the resolvers that read this table.
 *
 * A rule never stores concrete dates — it stores a MONTH/DAY (and optionally YEAR) pair in one
 * calendar, resolved against a queried Gregorian date each time. That is what lets a Hijri rule
 * (Eid al-Fitr, say) move correctly when the department's `hijri_offset_days` calibration
 * changes, rather than freezing to whatever Gregorian date it happened to fall on when someone
 * looked it up.
 */
class Holiday extends Model
{
    /** @use HasFactory<HolidayFactory> */
    use HasFactory;

    public const GREGORIAN = 'gregorian';

    public const HIJRI = 'hijri';

    /** @var list<string> */
    protected $fillable = [
        'institution_id',
        'name',
        'calendar',
        'month',
        'day',
        'year',
        'duration_days',
        'equity_tracked',
        'active',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'month' => 'integer',
            'day' => 'integer',
            'year' => 'integer',
            'duration_days' => 'integer',
            'equity_tracked' => 'boolean',
            'active' => 'boolean',
        ];
    }

    /**
     * Whether `$date` is the rule's ANCHOR day for its own year — the first day of the span,
     * never one of the `duration_days - 1` days that follow it. `Calendar::holidaysOn()` is what
     * walks the span backwards from a queried date asking this question of each candidate
     * anchor; this method only ever answers it for a single exact day.
     *
     * A Hijri rule converts `$date` through `Calendar::hijri()`, which applies the department's
     * `hijri_offset_days` to the GREGORIAN instant before conversion (Task 2) — so this method
     * resolves THROUGH the calibration, never around it, by construction: there is no separate
     * "Hijri holiday" code path that skips the shared conversion.
     */
    public function anchoredOn(CarbonImmutable $date): bool
    {
        if ($this->calendar === self::HIJRI) {
            $hijri = Calendar::hijri($date);

            return $hijri['month'] === $this->month
                && $hijri['day'] === $this->day
                && ($this->year === null || $hijri['year'] === $this->year);
        }

        return (int) $date->format('n') === $this->month
            && (int) $date->format('j') === $this->day
            && ($this->year === null || (int) $date->format('Y') === $this->year);
    }
}
