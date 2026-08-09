<?php

namespace App\Models;

use App\Support\Calendar;
use Database\Factories\MasterRotaAssignmentFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use RuntimeException;

/**
 * One date-bounded span of a person's assignment to a unit within a period (Munawib MR-02).
 *
 * EVERY ROW IS A SPAN — `starts_on`/`ends_on` are always set, both bounds inclusive. A
 * whole-period assignment is the degenerate split: one row whose bounds equal its period's. See
 * the migration's docblock (Decision B) for why there is no nullable "means the whole period"
 * shape and no parent/child span pair.
 *
 * NO SOFT DELETE (Decision E) — this is schedule structure, not a clinical row; the hash-chained
 * `audit_log` is the history.
 */
class MasterRotaAssignment extends Model
{
    /** @use HasFactory<MasterRotaAssignmentFactory> */
    use HasFactory;

    /** @var list<string> */
    protected $fillable = [
        'institution_id',
        'person_id',
        'period_id',
        'unit_id',
        'starts_on',
        'ends_on',
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
     * The two invariants the database cannot express (see the migration's docblock). Modelled on
     * `Period::booted()`, deliberately — same shape, same reasoning, same `whereDate` caveat.
     *
     * Containment is checked against the row's OWN period, and periods never overlap each other
     * (`Period::booted()`), so a per-period overlap check is sufficient: one person's spans cannot
     * overlap across two different periods. A second, global per-person check would be a weaker
     * duplicate of a fact already guaranteed.
     *
     * `whereDate`, not equality — `Period::booted()` documents the live hazard: the `date` cast
     * round-trips from MySQL as 'Y-m-d 00:00:00', so equality against a plain 'Y-m-d' never
     * matches.
     */
    protected static function booted(): void
    {
        static::saving(function (self $row): void {
            $period = Period::find($row->period_id);

            if ($period === null) {
                throw new RuntimeException('A master rota assignment must belong to a period.');
            }

            // Ahead of Calendar::ymd(), which takes no null and would raise a TypeError —
            // neither a RuntimeException nor an InvalidArgumentException, so
            // MasterRotaController's catches would let it through as the raw 500 this guard's
            // whole contract says never happens. Unreachable today (both writers always supply
            // dates, the FormRequest requires them, and both columns are NOT NULL); the point is
            // that the next write path does not have to be.
            if ($row->starts_on === null || $row->ends_on === null) {
                throw new RuntimeException('A rota assignment must have both a start and an end date.');
            }

            $from = Calendar::ymd($row->starts_on);
            $to = Calendar::ymd($row->ends_on);

            if ($to < $from) {
                throw new RuntimeException("A rota assignment ends ({$to}) before it starts ({$from}).");
            }

            $periodFrom = $period->starts_on->format(Calendar::YMD);
            $periodTo = $period->ends_on->format(Calendar::YMD);

            if ($from < $periodFrom || $to > $periodTo) {
                throw new RuntimeException(
                    "A rota assignment ({$from}..{$to}) must lie inside \"{$period->label}\" "
                    ."({$periodFrom}..{$periodTo}). A split that reaches outside its period is an "
                    .'assignment to a period nobody is looking at.'
                );
            }

            $clash = static::query()
                ->where('person_id', $row->person_id)
                ->where('period_id', $row->period_id)
                ->when($row->exists, fn ($q) => $q->whereKeyNot($row->getKey()))
                ->whereDate('starts_on', '<=', $to)
                ->whereDate('ends_on', '>=', $from)
                ->first();

            if ($clash !== null) {
                throw new RuntimeException(
                    "This span ({$from}..{$to}) overlaps an existing one "
                    ."({$clash->starts_on->format(Calendar::YMD)}..{$clash->ends_on->format(Calendar::YMD)}) "
                    .'for the same person in the same period. One person on two units on one day is a '
                    .'state the grid cannot render and the call roster cannot resolve.'
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
     * @return BelongsTo<Period, $this>
     */
    public function period(): BelongsTo
    {
        return $this->belongsTo(Period::class);
    }

    /**
     * @return BelongsTo<Unit, $this>
     */
    public function unit(): BelongsTo
    {
        return $this->belongsTo(Unit::class);
    }

    /**
     * @return BelongsTo<Institution, $this>
     */
    public function institution(): BelongsTo
    {
        return $this->belongsTo(Institution::class);
    }
}
