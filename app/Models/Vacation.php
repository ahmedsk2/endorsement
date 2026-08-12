<?php

namespace App\Models;

use App\Support\Calendar;
use Database\Factories\VacationFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use InvalidArgumentException;
use RuntimeException;

/**
 * A person's leave, overlaying whatever the master rota has them on (Munawib AR-05/MR-03). See
 * the migration's docblock (P1d Decision C) for why this carries NO `period_id`.
 *
 * `granularity` records how the leave was entered/is edited; the stored dates are always real
 * dates — a `week` booking is snapped by `App\Support\Rota\VacationBooking` before it ever reaches
 * this model. `source` records how the row was created.
 *
 * NO SOFT DELETE (Decision E) — the hash-chained `audit_log` is the history.
 */
class Vacation extends Model
{
    /** @use HasFactory<VacationFactory> */
    use HasFactory;

    public const GRANULARITY_WEEK = 'week';

    public const GRANULARITY_DATE = 'date';

    public const SOURCE_MANUAL = 'manual';

    public const SOURCE_IMPORT = 'import';

    /** @var list<string> */
    private const GRANULARITIES = [self::GRANULARITY_WEEK, self::GRANULARITY_DATE];

    /** @var list<string> */
    private const SOURCES = [self::SOURCE_MANUAL, self::SOURCE_IMPORT];

    /** @var list<string> */
    protected $fillable = [
        'institution_id',
        'person_id',
        'starts_on',
        'ends_on',
        'granularity',
        'source',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'starts_on' => 'date',
            'ends_on' => 'date',
        ];
    }

    /**
     * The invariants the database cannot express. Modelled on `MasterRotaAssignment::booted()` —
     * same shape, same `whereDate` caveat: against a plain `Y-m-d` string a `date`-cast column
     * compares wrongly on the BOUNDARY DAY — measured on SQLite, a bare `=` matches nothing, a
     * bare `<=` drops that day and a bare `>` gains it — so leave booked to end on a date would
     * be one day short of what was booked. `EndorsementController::updateSignoff()` carries the
     * canonical statement, the measurement, and the note that the engine attribution these
     * comments used to share was backwards.
     *
     * Overlap is checked per PERSON only — a vacation carries no period, so there is no
     * containment check to make, and two people may be on leave the same week without conflict.
     */
    protected static function booted(): void
    {
        static::saving(function (self $row): void {
            if (! in_array($row->granularity, self::GRANULARITIES, true)) {
                throw new InvalidArgumentException(
                    "Unknown vacation granularity \"{$row->granularity}\". Must be one of: "
                    .implode(', ', self::GRANULARITIES).'.'
                );
            }

            if (! in_array($row->source, self::SOURCES, true)) {
                throw new InvalidArgumentException(
                    "Unknown vacation source \"{$row->source}\". Must be one of: "
                    .implode(', ', self::SOURCES).'.'
                );
            }

            $from = Calendar::ymd($row->starts_on);
            $to = Calendar::ymd($row->ends_on);

            if ($to < $from) {
                throw new RuntimeException("A vacation ends ({$to}) before it starts ({$from}).");
            }

            $clash = static::query()
                ->where('person_id', $row->person_id)
                ->when($row->exists, fn ($q) => $q->whereKeyNot($row->getKey()))
                ->whereDate('starts_on', '<=', $to)
                ->whereDate('ends_on', '>=', $from)
                ->first();

            if ($clash !== null) {
                throw new RuntimeException(
                    "This leave ({$from}..{$to}) overlaps existing leave "
                    ."({$clash->starts_on->format(Calendar::YMD)}..{$clash->ends_on->format(Calendar::YMD)}) "
                    .'for the same person.'
                );
            }
        });
    }

    /**
     * @return BelongsTo<Person, $this>
     */
    public function person(): BelongsTo
    {
        return $this->belongsTo(Person::class);
    }

    /**
     * @return BelongsTo<Institution, $this>
     */
    public function institution(): BelongsTo
    {
        return $this->belongsTo(Institution::class);
    }

    /**
     * Both bounds INCLUSIVE — the same `whereDate` pair written once so no caller writes it
     * twice. A vacation "intersects" a range when it starts on or before the range's end and has
     * not ended before the range's start.
     *
     * @param  Builder<Vacation>  $query
     * @return Builder<Vacation>
     */
    public function scopeIntersecting(Builder $query, string $fromYmd, string $toYmd): Builder
    {
        return $query
            ->whereDate('starts_on', '<=', $toYmd)
            ->whereDate('ends_on', '>=', $fromYmd);
    }
}
