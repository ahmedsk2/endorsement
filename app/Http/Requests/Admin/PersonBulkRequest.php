<?php

namespace App\Http\Requests\Admin;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * LV-02's bulk operations over the roster: set level, set status (active), export.
 *
 * `Rule::exists('people', 'id')` runs on the raw query builder and NEVER sees the SoftDeletes
 * global scope — deliberately kept, not fixed: a retired person is a legitimate member of a bulk
 * selection (exporting a full roster including leavers, or re-activating one). Excluding trashed
 * ids here would make the exact case this validation exists to allow fail with "the selected
 * people.0 is invalid".
 */
class PersonBulkRequest extends FormRequest
{
    public const ACTIONS = ['set_level', 'set_active', 'export'];

    /** The route middleware (`cap:people.manage`) is the gate; nothing extra here. */
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'action' => ['required', 'string', Rule::in(self::ACTIONS)],
            // `min:1` refuses an empty selection outright; `max:500` is a sane bound on a single
            // batch — the roster this screen manages is a department's staff list, not a
            // hospital-wide directory.
            'ids' => ['required', 'array', 'min:1', 'max:500'],
            'ids.*' => ['integer', Rule::exists('people', 'id')],
            'level_id' => ['required_if:action,set_level', 'integer', Rule::exists('levels', 'id')],
            'active' => ['required_if:action,set_active', 'boolean'],
        ];
    }
}
