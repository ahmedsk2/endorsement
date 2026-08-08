<?php

namespace App\Casts;

use Illuminate\Contracts\Database\Eloquent\CastsAttributes;
use Illuminate\Database\Eloquent\Model;

/**
 * The `units.extra_row_fields` column, ALLOW-LISTED against the identity columns that
 * actually exist on `handovers` beyond the universal bed/mrn/patient_name.
 *
 * This is not a convenience cast. `extra_row_fields` drives
 * EndorsementController::validateRow()'s validation rules, and the validated array it
 * produces reaches `$handover->update($data)` with nothing overriding it. A `units` row is
 * administrator-editable configuration, not a compile-time constant, so an unexpected value
 * here is a live data-integrity hazard rather than a hypothetical one:
 *
 *   - `["unit_id"]`, `["institution_id"]` or `["author_user_id"]` are all in
 *     `Handover::$fillable`. Any of them would let a user holding only `endorsement.edit`
 *     move a clinical row to another unit, or rewrite the recorded author on evidence a
 *     sign-off has already frozen.
 *   - `["details"]` would silently borrow a short, generic validation rule and cap a
 *     20,000-character clinical narrative at 100 characters.
 *   - A non-array JSON value (or malformed JSON) would fatal the `foreach` that consumes it.
 *
 * So the cast, not the caller, is the enforcement point: both directions intersect the value
 * against ALLOWED, and anything outside it is silently dropped rather than trusted or thrown.
 * A misconfigured unit must degrade to "no extra columns", never fatal a clinical sheet — do
 * not "simplify" this back to a plain `array` cast. Non-string elements (a nested array or
 * object) are filtered out before the intersect, not passed to it — `array_intersect()`
 * string-casts every element it compares, and a nested array raises "Array to string
 * conversion", which the real HTTP kernel's `HandleExceptions` turns into a thrown
 * `ErrorException`. Both directions also de-duplicate: `Sheet.vue` keys its extra-field
 * columns on this list, and a repeated key would produce duplicate Vue keys and a duplicated
 * column.
 *
 * P0b's bounded custom fields (design §6.2) use a DIFFERENT column —
 * `handovers.extra_fields`, encrypted, driven by a `unit_field_definitions` table — so nothing
 * in P0b should ever write an unknown key here. Do not widen ALLOWED to accommodate P0b.
 *
 * @implements CastsAttributes<list<string>, array<int, string>|null>
 */
class ExtraRowFields implements CastsAttributes
{
    /**
     * The nullable per-unit identity columns that exist on `handovers` beyond the universal
     * bed/mrn/patient_name. Nothing outside this list may ever come out of, or go into, the
     * column — see the class docblock for why.
     *
     * @var list<string>
     */
    public const ALLOWED = ['dob', 'age', 'ward_unit'];

    /**
     * @param  array<string, mixed>  $attributes
     * @return list<string>
     */
    public function get(Model $model, string $key, mixed $value, array $attributes): array
    {
        $decoded = is_string($value) ? json_decode($value, true) : null;

        if (! is_array($decoded)) {
            return [];
        }

        $strings = array_filter($decoded, 'is_string');

        return array_values(array_unique(array_intersect($strings, self::ALLOWED)));
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    public function set(Model $model, string $key, mixed $value, array $attributes): string
    {
        $list = is_array($value) ? $value : [];

        $strings = array_filter($list, 'is_string');

        return json_encode(array_values(array_unique(array_intersect($strings, self::ALLOWED))));
    }
}
