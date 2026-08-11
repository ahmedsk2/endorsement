<?php

namespace App\Models;

use Database\Factories\ClinicFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A clinic (Munawib CL-01): an owning unit, a name, an ISO weekday, an AM/PM session, an optional
 * location and note, and an active flag. The one piece of the department's week the master rota
 * does not describe.
 *
 * `weekday` IS ISO-8601, MONDAY = 1 … SUNDAY = 7 — the same numbering as `Calendar::weekendDays()`,
 * `::weekStartIsoDay()` and `::weekdayColumns()`, and deliberately NOT Carbon's `dayOfWeek` (Sunday
 * = 0). The number is a recurrence-rule component, not a date: storing one converts nothing, which
 * is why it is a plain integer and not an enum. Its NAME belongs to `App\Support\Calendar` and
 * nowhere else, and `Calendar::weekdayLabel()` throws on 0 so `dayOfWeek` cannot enter through it.
 *
 * `name`, `location` and `note` are PLAIN TEXT and are never purified server-side — the
 * `handovers.extra_fields` contract, not the four `SanitizedHtml` clinical fields. Every consumer
 * escapes on render.
 *
 * NO SOFT DELETE (see the migration's docblock): schedule structure, not a clinical row. A clinic
 * that stopped running is deactivated.
 *
 * WRITES GO THROUGH `App\Support\Clinics\ClinicWriter` ONLY, proved at source level by
 * `ClinicWritersAreSingularTest`. There is deliberately no `booted()` guard here, unlike
 * `MasterRotaAssignment`: the constraints that matter are on the CHILD table and on the
 * relationship between the child set and `attendee_mode`, which a per-row model hook cannot see
 * whole. `person_levels`/`LevelAssignment` settled the same question the same way.
 */
class Clinic extends Model
{
    /** @use HasFactory<ClinicFactory> */
    use HasFactory;

    /**
     * The sessions a clinic may run in: value => human label.
     *
     * ONE list, which both OFFERS the choice and validates it — the `SignoffPickers` /
     * `Unit::BAR_CLASSES` / `Institution::CONTACT_VISIBILITIES` discipline. A picker's write-side
     * validation must match what it offers, per field (D9), and a predicate written twice is two
     * predicates that drift.
     *
     * The keys sort AM before PM lexicographically, which is why `scopeOrdered()` can order on the
     * raw column and still read chronologically. If a third session is ever added (an evening
     * clinic), that coincidence ends and the scope needs an explicit ordering — noted here rather
     * than discovered later.
     */
    public const SESSIONS = [
        'AM' => 'Morning',
        'PM' => 'Afternoon',
    ];

    /** CL-02's default: everyone the master rota has on the owning unit that day. */
    public const MODE_ROTATORS = 'rotators';

    /** Those rotators whose training level ON THAT DATE is in the attached set. */
    public const MODE_LEVELS = 'levels';

    /** Exactly the attached people, without consulting the rota at all. */
    public const MODE_NAMED = 'named';

    /**
     * CL-02's refinement modes: value => human label. Offered and validated from one place, same
     * reason as SESSIONS.
     *
     * A MODE ON THE PARENT rather than a bag of typed rules in the child: it keeps
     * `clinic_attendees` homogeneous per clinic, lets one writer enforce that, and removes a
     * precedence question nobody has answered. There is no exclude rule (owner decision 7,
     * additive if ever asked for).
     */
    public const ATTENDEE_MODES = [
        self::MODE_ROTATORS => 'Everyone rotating on this unit',
        self::MODE_LEVELS => 'Rotators at these training levels',
        self::MODE_NAMED => 'These people only',
    ];

    /** @var list<string> */
    protected $fillable = [
        'institution_id',
        'unit_id',
        'name',
        'weekday',
        'session',
        'location',
        'note',
        'attendee_mode',
        'active',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'weekday' => 'integer',
            'active' => 'boolean',
        ];
    }

    /**
     * @return BelongsTo<Unit, $this>
     */
    public function unit(): BelongsTo
    {
        return $this->belongsTo(Unit::class);
    }

    /**
     * The REFINEMENT RULE for this clinic, never a resolved roster (Decision B). Empty by
     * definition in `rotators` mode. `ClinicRoster::forDate()` turns these rows plus the master
     * rota into people, on a date, at read time.
     *
     * @return HasMany<ClinicAttendee, $this>
     */
    public function attendees(): HasMany
    {
        return $this->hasMany(ClinicAttendee::class);
    }

    /**
     * @return BelongsTo<Institution, $this>
     */
    public function institution(): BelongsTo
    {
        return $this->belongsTo(Institution::class);
    }

    /**
     * @param  Builder<Clinic>  $query
     * @return Builder<Clinic>
     */
    public function scopeActive(Builder $query): Builder
    {
        return $query->where('active', true);
    }

    /**
     * The department's clinics in the order a map reads them: down the week, then AM before PM,
     * then by name so two rooms in one session have a stable order.
     *
     * The ISO weekday orders 1..7 (Monday first) REGARDLESS of where this department's week starts.
     * Rotating to the department's own start is a presentation concern and belongs to
     * `Calendar::weekdayColumns()`, which is the one thing that knows the order the week runs in —
     * putting a second answer in a query scope is exactly the two-definitions drift AR-08 forbids.
     *
     * @param  Builder<Clinic>  $query
     * @return Builder<Clinic>
     */
    public function scopeOrdered(Builder $query): Builder
    {
        return $query->orderBy('weekday')->orderBy('session')->orderBy('name')->orderBy('id');
    }
}
