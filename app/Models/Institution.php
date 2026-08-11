<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Institution extends Model
{
    public const PERIOD_MONTHS = 'months';

    public const PERIOD_WEEK_BLOCKS = 'week_blocks';

    /**
     * Offered and labelled from ONE list — the same discipline CONTACT_VISIBILITIES below
     * follows, and for the same reason: the calendar settings screen offers these and the
     * setup checklist reports which one is in force, and two hand-written label pairs would
     * eventually disagree about what a period system is called.
     */
    public const PERIOD_TYPES = [
        self::PERIOD_WEEK_BLOCKS => 'Week blocks',
        self::PERIOD_MONTHS => 'Calendar months',
    ];

    /** The bounds a calibration may take. Beyond this it is a wrong timezone, not an offset. */
    public const HIJRI_OFFSET_BOUNDS = [-2, 2];

    public const CONTACT_ADMINS = 'admins';

    public const CONTACT_MEMBERS = 'members';

    /** Offered and validated from ONE list — the SignoffPickers discipline, applied small. */
    public const CONTACT_VISIBILITIES = [
        self::CONTACT_ADMINS => 'Administrators and roster managers only',
        self::CONTACT_MEMBERS => 'Any signed-in member',
    ];

    /**
     * @var list<string>
     */
    protected $fillable = [
        'name',
        'code',
        'active',
        'hijri_enabled',
        'hijri_offset_days',
        'weekend_days',
        'period_type',
        'block_weeks',
        'academic_year_start',
        'contact_visibility',
    ];

    /**
     * MySQL cannot carry a literal DEFAULT on a JSON column (weekend_days, block_weeks), so
     * their defaults live here instead — applied to every row created after the
     * 2026_08_12_120001 migration. Pre-encoded JSON: this is the RAW attribute value Eloquent
     * stores before the 'array' cast below decodes it on read. The migration's own backfill
     * covers the one row that already existed when it ran.
     *
     * hijri_enabled/hijri_offset_days/period_type DO carry a DB-level default (the column
     * types support it), but that default is only visible after a re-fetch — Eloquent never
     * reads it back into the in-memory model after INSERT. Repeating it here keeps a freshly
     * created, not-yet-reloaded Institution instance consistent with what the database will
     * actually hold, and keeps every calendar default defined in exactly one place.
     *
     * @var array<string, mixed>
     */
    protected $attributes = [
        'hijri_enabled' => true,
        'hijri_offset_days' => 0,
        'weekend_days' => '[5,6]',
        'period_type' => self::PERIOD_WEEK_BLOCKS,
        'block_weeks' => '[4,4,4,4,4,4,4,4,4,4,4,4,5]',
        'contact_visibility' => self::CONTACT_ADMINS,
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'active' => 'boolean',
            'hijri_enabled' => 'boolean',
            'hijri_offset_days' => 'integer',
            'weekend_days' => 'array',
            'block_weeks' => 'array',
            'academic_year_start' => 'date',
        ];
    }

    /**
     * @return HasMany<User, $this>
     */
    public function users(): HasMany
    {
        return $this->hasMany(User::class);
    }

    /**
     * The single institution this deployment belongs to (D11: one database, one customer).
     *
     * Returns null when there is none — a deployment that has not been seeded — or when there
     * is more than one, because in that case there is no right answer and guessing would stamp
     * clinical provenance with a coin flip. Callers treat null as "leave it NULL".
     */
    public static function current(): ?self
    {
        $rows = static::query()->where('active', true)->limit(2)->get();

        return $rows->count() === 1 ? $rows->first() : null;
    }
}
