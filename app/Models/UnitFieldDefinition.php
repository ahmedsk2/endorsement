<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One custom handover-sheet field a unit has defined for itself (design §6.2, "Ceiling 2").
 * This row is only the SHAPE of the field — key, label, type, whether it is required. The
 * value a clinician enters lives elsewhere, in `handovers.extra_fields`, keyed by `key`.
 *
 * `active` defaults to true, the deliberate mirror image of `units.active` defaulting to
 * false: a half-configured unit must stay inert, but a definition someone bothered to create
 * is meant to be used — and it is inert anyway until a unit actually renders it. Deactivating
 * a definition hides it from `Unit::fieldDefinitions()`; it does not, and must never, delete
 * the row or the values stored under its key. Clinical values must survive the retirement of
 * their definition.
 */
class UnitFieldDefinition extends Model
{
    /**
     * @var list<string>
     */
    protected $fillable = [
        'unit_id',
        'key',
        'label',
        'type',
        'options',
        'required',
        'display_order',
        'active',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'options' => 'array',
            'required' => 'boolean',
            'display_order' => 'integer',
            'active' => 'boolean',
        ];
    }

    /** @return BelongsTo<Unit, $this> */
    public function unit(): BelongsTo
    {
        return $this->belongsTo(Unit::class);
    }

    /**
     * @param  Builder<UnitFieldDefinition>  $query
     * @return Builder<UnitFieldDefinition>
     */
    public function scopeActive(Builder $query): Builder
    {
        return $query->where('active', true);
    }

    /**
     * @param  Builder<UnitFieldDefinition>  $query
     * @return Builder<UnitFieldDefinition>
     */
    public function scopeOrdered(Builder $query): Builder
    {
        return $query->orderBy('display_order')->orderBy('id');
    }
}
