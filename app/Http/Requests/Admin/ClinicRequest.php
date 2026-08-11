<?php

namespace App\Http\Requests\Admin;

use App\Models\Clinic;
use App\Support\Clinics\ClinicPickers;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Munawib CL-01 write validation — create and update, one set of rules for both.
 *
 * EVERY CLOSED SET COMES FROM THE PLACE THAT OFFERS IT. `session` reads `Clinic::SESSIONS`, the same
 * constant the picker renders; the owning unit reads `ClinicPickers::unitRule()`, the same predicate
 * that builds the offered list. D9: a picker's write-side validation must match what it offers, per
 * field, and a list written down twice is two lists that drift.
 *
 * `weekday` IS ISO-8601, MONDAY = 1 … SUNDAY = 7 — `between:1,7` with no zero, because zero is
 * exactly what Carbon's `dayOfWeek` calls Sunday and admitting it would silently make this column a
 * second numbering scheme, every clinic a day out with no error anywhere. The rule mirrors
 * `ClinicWriter`'s own check rather than replacing it: this one produces a message under the field,
 * that one is the guarantee.
 *
 * `name`, `location` and `note` are PLAIN TEXT (the `handovers.extra_fields` contract) and are never
 * purified server-side — a clinic legitimately called "ENT <> Audiology" must survive the round trip
 * byte-identical. Length is the only thing bounded here; escaping is the renderer's job.
 */
class ClinicRequest extends FormRequest
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
            // Offered and accepted from ONE predicate: active AND owns clinics. A bare
            // `exists:units,id` would accept a retired or non-owning unit that the picker never
            // showed, and `ClinicWriter` would then refuse it by throwing — a 500 where a message
            // belongs.
            'unit_id' => ['required', 'integer', ClinicPickers::unitRule()],
            'name' => ['required', 'string', 'max:120'],
            'weekday' => ['required', 'integer', 'between:1,7'],
            'session' => ['required', 'string', Rule::in(array_keys(Clinic::SESSIONS))],
            'location' => ['nullable', 'string', 'max:120'],
            'note' => ['nullable', 'string', 'max:500'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'unit_id.exists' => 'That unit does not own clinics, or has been retired. Tick "owns '
                .'clinics" on Admin → Structure → Units first.',
            'weekday.between' => 'A clinic runs on one day of the week, Monday through Sunday.',
        ];
    }
}
