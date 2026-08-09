<?php

namespace App\Http\Requests\Admin;

use App\Models\Person;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * PE-01's write validation — the roster's full field set.
 *
 * `position` is validated here (an out-of-range value is a 422 before anything is written), but
 * the WRITE itself never touches `people.position` directly — the controller routes it through
 * `App\Support\PositionChange::apply()`, the one definition of a position change (Decision C).
 */
class PersonRequest extends FormRequest
{
    /** Position 1 (Nurse) is RETIRED — matches UserManagementController::POSITIONS exactly. */
    public const POSITIONS = [0, 2, 3, 4, 5];

    /** The route middleware (`cap:people.manage`) is the gate; nothing extra here. */
    public function authorize(): bool
    {
        return true;
    }

    /**
     * `Rule::unique` runs a raw `WHERE email = ?` against the submitted value, while every
     * stored address is normalised on write (`Person::normalizeEmail()`). Normalising BEFORE
     * validation, not after, is what UserManagementController::updateProfile() already
     * documents: depending on MySQL's collation to catch the difference is an accident, not a
     * check.
     */
    protected function prepareForValidation(): void
    {
        $this->merge(['email' => Person::normalizeEmail($this->input('email'))]);
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $person = $this->route('person');
        $id = $person instanceof Person ? $person->getKey() : null;

        return [
            'full_name' => ['required', 'string', 'max:255'],
            // The rota handle. UNIQUE OUTRIGHT and institution-blind by design (D11) — the
            // create-table migration's own docblock explains why a composite unique would be
            // toothless for exactly the bootstrap and fixture rows. Soft-deleted people still
            // occupy the index, so `withoutTrashed()` is NOT used: re-adding someone who left
            // must collide and be resolved, not silently duplicate a human.
            'short_name' => ['nullable', 'string', 'max:50', Rule::unique('people', 'short_name')->ignore($id)],
            'position' => ['required', 'integer', Rule::in(self::POSITIONS)],
            // TWO checks, not one, and they catch different things:
            //  - `Person::accountEmailRule()` is the ONE definition of "already an account" — a
            //    roster-only match is deliberately NOT a failure THERE, because contexts that use
            //    it alone (invitation acceptance, registration approval) then CLAIM the matched
            //    row rather than insert a second one (design §5.2.4).
            //  - `people.email` also carries a genuine UNIQUE column, and this screen does a
            //    plain create/update with no claim-or-merge step — so a roster-only match here
            //    would pass `accountEmailRule()` and then hit the raw DB constraint as an
            //    uncaught 500 (caught empirically: `PersonCrudTest` reproduced it before this
            //    line existed). `Rule::unique` is the second check, closing that gap with a 422
            //    naming the field instead. Soft-deleted people still occupy the index (same
            //    reasoning as `short_name` above), so `withoutTrashed()` is NOT used here either.
            'email' => [
                'nullable', 'email', 'max:255',
                Person::accountEmailRule($id),
                Rule::unique('people', 'email')->ignore($id),
            ],
            'phone' => ['nullable', 'string', 'max:32'],
            // Not `date`: PHP's implicit date-string parser once accepted a relative phrase like
            // "+5 years" and created a real backdated clinical row (P1 finding 3) — a lenient
            // sibling anywhere is the same bug waiting to happen.
            'joined_at' => ['nullable', 'date_format:Y-m-d'],
            'notes' => ['nullable', 'string', 'max:5000'],
            'constraints' => ['nullable', 'array'],
            'external' => ['required', 'boolean'],
            'active' => ['required', 'boolean'],
        ];
    }
}
