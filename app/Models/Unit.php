<?php

namespace App\Models;

use App\Support\UnitProfile;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

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
            'extra_row_fields' => 'array',
        ];
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
}
