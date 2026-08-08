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
 *
 * `key` is guarded to `^[a-z][a-z0-9_]{0,63}$` on save (below) — there is no admin UI yet, so a
 * throwing `saving` guard is the whole enforcement. Two independent reasons, not one:
 *
 *  1. Laravel's `extra_fields.*` validation-rule namespacing reads a dotted rule key as a
 *     NESTED path (`extra_fields.a.b` never reaches the flat map key `a.b`) and a `*`-bearing
 *     rule key SILENTLY never applies at all (`extra_fields.x*y` matches nothing, so a
 *     `required`/`in:` rule on that definition is a no-op). A key shaped like this would make
 *     its own validation unenforceable without any error at write time.
 *  2. It removes `App\Casts\EncryptedJson`'s numeric-key hazard at the source — see that
 *     class's `normalize()` — though that cast fixes it independently anyway (defence in
 *     depth: historical rows may already hold numeric keys from before this guard existed).
 *
 * `type` is guarded to `text|date|select` in the same place, for the same reason: nothing else
 * in this table constrains it, and an admin UI cannot validate a value it does not yet control.
 */
class UnitFieldDefinition extends Model
{
    /** Same shape `pickerRule()`-style guards use elsewhere: enforced at save, not just intake. */
    private const KEY_PATTERN = '/^[a-z][a-z0-9_]{0,63}$/';

    /** @var list<string> */
    private const VALID_TYPES = ['text', 'date', 'select'];

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

    protected static function booted(): void
    {
        static::saving(function (self $definition): void {
            if (! is_string($definition->key) || preg_match(self::KEY_PATTERN, $definition->key) !== 1) {
                throw new \InvalidArgumentException(
                    'UnitFieldDefinition key must match '.self::KEY_PATTERN.
                    ' (lowercase, starts with a letter, letters/digits/underscore only, max 64 chars).'
                );
            }

            // null is left alone (same opt-out-of-the-in-memory-value pattern `active` already
            // relies on, per its own docblock and test_active_defaults_to_true): an omitted
            // `type` never reaches the INSERT, so the column's own `default('text')` — a value
            // already inside VALID_TYPES — applies at the database level. Only an EXPLICITLY
            // supplied, invalid type is refused here.
            if ($definition->type !== null && ! in_array($definition->type, self::VALID_TYPES, true)) {
                throw new \InvalidArgumentException(
                    'UnitFieldDefinition type must be one of: '.implode('|', self::VALID_TYPES).'.'
                );
            }
        });
    }

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
