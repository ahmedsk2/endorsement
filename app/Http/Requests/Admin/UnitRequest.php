<?php

namespace App\Http\Requests\Admin;

use App\Models\Unit;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Munawib UN-01…05 write validation.
 *
 * Two rules here exist because of the `code` mutator (Unit.php): it normalises what is STORED,
 * not what a query compares. Both the uniqueness check and the reserved-code check therefore
 * run against the NORMALISED value, or `picu` passes uniqueness and collides at insert, and
 * ` today ` passes the reserved check and trips the model's saving guard as a raw 500.
 */
class UnitRequest extends FormRequest
{
    /** The route middleware (`cap:structure.manage`) is the gate; nothing extra here. */
    public function authorize(): bool
    {
        return true;
    }

    /** Normalise BEFORE validation so every rule below sees what will actually be stored. */
    protected function prepareForValidation(): void
    {
        if ($this->has('code')) {
            $this->merge(['code' => strtoupper(trim((string) $this->input('code')))]);
        }
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $unit = $this->route('unit');
        $id = $unit instanceof Unit ? $unit->getKey() : null;

        return [
            'code' => [
                'required', 'string', 'max:20', 'regex:/^[A-Z0-9]+$/',
                Rule::notIn(Unit::RESERVED_CODES),
                Rule::unique('units', 'code')->ignore($id),
            ],
            'name' => ['required', 'string', 'max:255'],
            'name2' => ['nullable', 'string', 'max:255'],
            'display_order' => ['required', 'integer', 'between:1,9999'],
            'active' => ['required', 'boolean'],
            'training_rotation' => ['required', 'boolean'],
            'call_target' => ['required', 'boolean'],
            'clinic_owner' => ['required', 'boolean'],
            'aliases' => ['present', 'array', 'max:50'],
            'aliases.*' => ['string', 'max:100'],
            // Offered and validated from ONE list, so the select and the gate cannot drift.
            'bar_class' => ['required', 'string', Rule::in(array_keys(Unit::BAR_CLASSES))],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'code.not_in' => 'That code is reserved by a route under /endorsement — a unit with it '
                .'would be permanently unreachable. Choose another.',
            'code.regex' => 'A unit code is letters and digits only: it is the address in '
                .'/endorsement/<code>.',
            'code.unique' => 'Another unit already uses that code.',
        ];
    }
}
