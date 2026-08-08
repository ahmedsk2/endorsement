<?php

namespace App\Models;

use App\Casts\ExtraRowFields;
use App\Support\UnitProfile;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A clinical unit. Since design §6.1 this row carries every per-unit difference — which
 * identity columns the sheet shows, how the consultant sign-off is shaped, the print labels
 * and the hue — so adding a department is configuration, not code.
 */
class Unit extends Model
{
    /**
     * @var list<string>
     */
    protected $fillable = [
        'code',
        'name',
        'display_order',
        'active',
        'extra_row_fields',
        'bed_label',
        'consultant_pair',
        'consultant_by_label',
        'bar_class',
        'print_plan_label',
        'print_narrative_label',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'display_order' => 'integer',
            'active' => 'boolean',
            'consultant_pair' => 'boolean',
            'extra_row_fields' => ExtraRowFields::class,
        ];
    }

    /**
     * Normalizes what gets STORED on write — it has no effect on a query's WHERE value. A
     * unit created lowercase or padded is stored `NUR`/`ICU`, so `Unit::codes()` (which
     * plucks stored codes VERBATIM into a `whereIn`) always yields the normalized form.
     *
     * It does NOT retroactively fix existing rows, and it does NOT cover writes that bypass
     * Eloquent (e.g. the migration's own `DB::table('units')->update()`). And because it only
     * touches the attribute being SET, a lookup built from raw user input — e.g.
     * `where('code', $input)` — is not normalized by this and must go through
     * `Unit::findByCode()` instead, which normalizes the lookup key the same way.
     */
    protected function code(): Attribute
    {
        return Attribute::make(set: fn ($v) => $v === null ? null : strtoupper(trim($v)));
    }

    /**
     * Resolve a unit by code, normalizing the way the `code` mutator does on write.
     *
     * The mutator normalizes what is STORED; it does not touch a query's WHERE
     * value, so `where('code', $userInput)` would miss a row that differs only in
     * case or whitespace. Every code lookup must go through here.
     */
    public static function findByCode(string $code): ?self
    {
        return static::query()->where('code', strtoupper(trim($code)))->first();
    }

    /**
     * @param  Builder<Unit>  $query
     * @return Builder<Unit>
     */
    public function scopeActive(Builder $query): Builder
    {
        return $query->where('active', true);
    }

    /**
     * @param  Builder<Unit>  $query
     * @return Builder<Unit>
     */
    public function scopeOrdered(Builder $query): Builder
    {
        return $query->orderBy('display_order')->orderBy('id');
    }

    /**
     * Active unit codes in display order — the replacement for the old
     * `UnitProfile::codes()` static registry.
     *
     * @return list<string>
     */
    public static function codes(): array
    {
        return static::query()->active()->ordered()->pluck('code')->all();
    }

    /** The value object every surface reads (sheet columns, sign-off, print, hue). */
    public function profile(): UnitProfile
    {
        return UnitProfile::fromUnit($this);
    }

    /**
     * This unit's custom handover-sheet fields (design §6.2, "Ceiling 2"), active-only and in
     * display order. A retired definition disappears from here without deleting its row or
     * the values stored under its key elsewhere — see UnitFieldDefinition's docblock.
     *
     * @return HasMany<UnitFieldDefinition, $this>
     */
    public function fieldDefinitions(): HasMany
    {
        return $this->hasMany(UnitFieldDefinition::class)->active()->ordered();
    }
}
