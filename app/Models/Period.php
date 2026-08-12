<?php

namespace App\Models;

use App\Support\Calendar;
use Database\Factories\PeriodFactory;
use DateTimeInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use RuntimeException;

/**
 * A department's rota period — either a calendar month or a week-measured block (owner
 * decision 2, MR-01 in full). `kind` is stored PER ROW, not read from the institution's
 * CURRENT `period_type`, so a department that switches period systems mid-year does not
 * silently relabel periods it already scheduled against.
 *
 * Owner decision 4 (round 2, 2026-08-08): a week-block year's final block's length VARIES
 * year to year (it absorbs whatever remains before the following year's fixed start date) —
 * see `App\Support\PeriodGenerator::weekBlocks()`. Nothing here assumes a fixed block length.
 */
class Period extends Model
{
    /** @use HasFactory<PeriodFactory> */
    use HasFactory;

    public const MONTH = 'month';

    public const WEEK_BLOCK = 'week_block';

    /** @var list<string> */
    protected $fillable = [
        'institution_id',
        'academic_year',
        'kind',
        'position',
        'label',
        'starts_on',
        'ends_on',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'position' => 'integer',
            'starts_on' => 'date',
            'ends_on' => 'date',
        ];
    }

    /**
     * The overlap guard the database cannot express as a constraint: two periods covering one
     * day in the same academic year means one person is on two rotations that day, which the
     * master rota grid has no way to render and the call roster no way to resolve.
     *
     * Scoped by `academic_year` only, NOT `institution_id` — D11:
     * `InstitutionProvenanceTest::test_no_query_filters_on_institution_id` forbids
     * `institution_id` as a query filter anywhere in app/ (it is provenance/grouping, never an
     * isolation boundary — the migration's docblock explains why the unique index matches).
     *
     * `whereDate`, not equality — `EndorsementController::updateSignoff()` carries the canonical
     * statement and the measurement behind it. In short: against a plain `Y-m-d` string a
     * `date`-cast column compares wrongly on the BOUNDARY DAY, because the stored value sorts
     * after the bare needle for the same day. Measured on SQLite, a bare `=` matches nothing, a
     * bare `<=` drops the boundary day and a bare `>` gains it. `whereDate()` is the answer on
     * both engines, so the rule needs no engine attribution — and the attribution this comment
     * used to carry was backwards.
     */
    protected static function booted(): void
    {
        static::saving(function (self $period): void {
            $clash = static::query()
                ->where('academic_year', $period->academic_year)
                ->when($period->exists, fn ($q) => $q->whereKeyNot($period->getKey()))
                ->whereDate('starts_on', '<=', $period->ends_on)
                ->whereDate('ends_on', '>=', $period->starts_on)
                ->first();

            if ($clash !== null) {
                throw new RuntimeException(
                    "Period \"{$period->label}\" overlaps \"{$clash->label}\". Two periods "
                    .'covering one day means one person is on two rotations that day, which the '
                    .'master rota grid has no way to render and the call roster no way to resolve.'
                );
            }
        });
    }

    /**
     * @return BelongsTo<Institution, $this>
     */
    public function institution(): BelongsTo
    {
        return $this->belongsTo(Institution::class);
    }

    /**
     * @param  Builder<Period>  $query
     * @return Builder<Period>
     */
    public function scopeForYear(Builder $query, string $academicYear): Builder
    {
        return $query->where('academic_year', $academicYear);
    }

    /**
     * @param  Builder<Period>  $query
     * @return Builder<Period>
     */
    public function scopeOrdered(Builder $query): Builder
    {
        return $query->orderBy('starts_on');
    }

    /** Both bounds INCLUSIVE — the same idiom `Person::levelAt()` uses. */
    public function contains(string|DateTimeInterface $date): bool
    {
        $day = Calendar::ymd($date);

        return $day >= $this->starts_on->format(Calendar::YMD)
            && $day <= $this->ends_on->format(Calendar::YMD);
    }
}
