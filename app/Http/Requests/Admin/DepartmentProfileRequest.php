<?php

namespace App\Http\Requests\Admin;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Munawib ST-01 "profile" write validation — Decision G.
 *
 * ONE writable field, deliberately. `institutions.code` is absent from `rules()` and therefore
 * absent from `validated()`, which is what actually stops it being written: the model lists it
 * as fillable (the seeder needs it), so a controller filling from the raw request would accept
 * a submitted code and re-key the row `ReferenceSeeder` matches on. Rendering the input
 * disabled is a display detail; this is the server-side half, and it is the half that counts.
 *
 * `max:255` mirrors the column (`$table->string('name')`), read as the bound it is rather than
 * a shorter one invented here — a department that types its full legal name should not be
 * refused by a limit no storage layer asked for.
 */
class DepartmentProfileRequest extends FormRequest
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
        return [
            'name' => ['required', 'string', 'max:255'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'name.required' => 'The department needs a name — it appears on every screen and '
                .'on the printed handover sheet.',
        ];
    }
}
