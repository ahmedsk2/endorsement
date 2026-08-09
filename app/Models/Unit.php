<?php

namespace App\Models;

use App\Casts\ExtraRowFields;
use App\Casts\UnitAliases;
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
     * Codes that can never be a unit, because routes/web.php declares them as literal segments
     * before /endorsement/{unit}. A unit with one of these codes would be permanently shadowed
     * by the earlier route and unreachable — silently, with no error at creation and a 404 at
     * use. Impossible while the registry was hardcoded; reachable the moment a UI creates units.
     *
     * Kept in sync with the router by ReservedUnitCodesTest, which derives the list from the
     * registered routes rather than trusting this constant.
     */
    public const RESERVED_CODES = ['TODAY', 'COMPLIANCE', 'ROWS'];

    /**
     * The unit colour palette: class => human label. `bar_class` IS the unit's colour; there
     * is deliberately no second `color` column (P1b Decision B — two definitions of one fact).
     * This map both OFFERS the choice and validates it, so the two cannot drift.
     *
     * Widened by P1b Task 3 with four hue-named entries, so a fifth department has a colour.
     */
    public const BAR_CLASSES = [
        'channel-bar-picu' => 'Teal',
        'channel-bar-nicu' => 'Indigo',
        'channel-bar-scbu' => 'Violet',
        'channel-bar-ward' => 'Plum',
    ];

    /**
     * @var list<string>
     */
    protected $fillable = [
        'code',
        'name',
        'name2',
        'display_order',
        'active',
        'training_rotation',
        'call_target',
        'clinic_owner',
        'aliases',
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
            'training_rotation' => 'boolean',
            'call_target' => 'boolean',
            'clinic_owner' => 'boolean',
            'aliases' => UnitAliases::class,
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
     * Munawib UN-03's typo-tolerant resolver: exact code first, then any unit whose alias list
     * matches case- and whitespace-insensitively.
     *
     * CODE WINS. An exact identity beats another unit's typo-tolerance hint, or an alias could
     * shadow a real unit and silently redirect an import.
     *
     * Loads the whole unit set and matches in PHP rather than querying JSON: units number in
     * the tens, JSON containment predicates are MySQL-only, and this schema runs on SQLite
     * under test. `findByCode()` remains the resolver for anything ROUTING — a URL segment must
     * never resolve through a fuzzy alias.
     *
     * No production consumer yet: ST-04's roster import is P1c and the rota import is P1d.
     * Shipped with the column so the two arrive together rather than the import inventing its
     * own matcher.
     */
    public static function findByCodeOrAlias(string $value): ?self
    {
        if (($exact = static::findByCode($value)) !== null) {
            return $exact;
        }

        $needle = UnitAliases::fold($value);

        if ($needle === '') {
            return null;
        }

        return static::query()->get()->first(
            fn (self $unit): bool => collect($unit->aliases)
                ->contains(fn (string $alias): bool => UnitAliases::fold($alias) === $needle)
        );
    }

    /**
     * Refuse a reserved code on every write path — a seeder, a console command, a factory and a
     * future controller are all covered by this one gate. Compares against the NORMALIZED code,
     * since the `code` Attribute above uppercases and trims before this fires.
     *
     * There is no unit-creation UI yet (P1), so this model guard is the whole enforcement today.
     * When one lands, it must surface this as a validation message (`Rule::notIn(self::
     * RESERVED_CODES)`) rather than let this exception reach the user raw.
     */
    protected static function booted(): void
    {
        static::saving(function (self $unit): void {
            if (in_array($unit->code, self::RESERVED_CODES, true)) {
                throw new \InvalidArgumentException(
                    "Unit code [{$unit->code}] is reserved by a route under /endorsement and would "
                    .'be unreachable. Choose another code.'
                );
            }
        });
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
