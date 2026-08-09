<?php

namespace App\Models;

use Database\Factories\LevelFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A training level (Munawib LV-01), e.g. R1 / PGY-2. Orthogonal to `people.position` (design
 * §5.1): a person is a Resident (job role) AND an R1 (training level) — two different facts, each
 * with exactly one home.
 *
 * A level is never deleted once it has history (`restrictOnDelete` from `person_levels`);
 * retiring one sets `active = false` instead, and past history still resolves through it.
 */
class Level extends Model
{
    /** @use HasFactory<LevelFactory> */
    use HasFactory;

    /**
     * @var list<string>
     */
    protected $fillable = [
        'institution_id',
        'code',
        'name',
        'display_order',
        'active',
        'external',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'display_order' => 'integer',
            'active' => 'boolean',
            'external' => 'boolean',
        ];
    }

    /**
     * @return HasMany<PersonLevel, $this>
     */
    public function personLevels(): HasMany
    {
        return $this->hasMany(PersonLevel::class);
    }

    /**
     * @return BelongsTo<Institution, $this>
     */
    public function institution(): BelongsTo
    {
        return $this->belongsTo(Institution::class);
    }

    /**
     * @param  Builder<Level>  $query
     * @return Builder<Level>
     */
    public function scopeActive(Builder $query): Builder
    {
        return $query->where('active', true);
    }

    /**
     * @param  Builder<Level>  $query
     * @return Builder<Level>
     */
    public function scopeOrdered(Builder $query): Builder
    {
        return $query->orderBy('display_order')->orderBy('id');
    }

    /**
     * Munawib LV-01's `external` flag: `EXT` sits outside the training ladder and is never
     * promoted. `internal()` is the levels a promotion picker (P1c) offers.
     *
     * @param  Builder<Level>  $query
     * @return Builder<Level>
     */
    public function scopeInternal(Builder $query): Builder
    {
        return $query->where('external', false);
    }
}
