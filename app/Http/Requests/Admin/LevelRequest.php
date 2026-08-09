<?php

namespace App\Http\Requests\Admin;

use App\Models\Level;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Munawib LV-01 write validation.
 *
 * `levels.code` is UNIQUE outright, institution-blind by design (the create-table migration's
 * own docblock explains why: `institution_id` is nullable, and a UNIQUE index treats NULLs as
 * distinct on both MySQL/InnoDB and SQLite, so a composite unique would be toothless for
 * exactly the bootstrap and fixture rows). Unlike `Unit::code`, `Level::code` carries no
 * write-time mutator — a level code is never a URL segment, so there is no routing-safety
 * reason to normalise it, and none is added here.
 */
class LevelRequest extends FormRequest
{
    /** The route middleware (`cap:structure.manage`) is the gate; nothing extra here. */
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $level = $this->route('level');
        $id = $level instanceof Level ? $level->getKey() : null;

        return [
            'code' => [
                'required', 'string', 'max:20',
                Rule::unique('levels', 'code')->ignore($id),
            ],
            'name' => ['required', 'string', 'max:255'],
            'display_order' => ['required', 'integer', 'between:1,9999'],
            'active' => ['required', 'boolean'],
            'external' => ['required', 'boolean'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'code.unique' => 'Another level already uses that code.',
        ];
    }
}
