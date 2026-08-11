<?php

namespace App\Http\Requests\Admin;

use App\Models\Clinic;
use App\Support\Clinics\ClinicPickers;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Munawib CL-02's refinement, validated WHOLE before `ClinicWriter::setAttendees()` sees it.
 *
 * THE MODE AND THE SET ARRIVE TOGETHER, ALWAYS — one endpoint, one payload, one transaction. They
 * are not two edits: splitting them would admit a moment where a `levels` clinic holds person rows,
 * which is one of the three states no database engine this schema runs on can refuse.
 *
 * WHAT IS AND IS NOT THIS CLASS'S JOB. It bounds the payload and checks that every id names
 * something the screen actually offered (D9, one predicate per field via `ClinicPickers`). It does
 * NOT enforce the exactly-one-of, no-duplicate and homogeneous-with-the-mode rules — those live in
 * `ClinicWriter`, which is the only writer and therefore the only place they can be a guarantee
 * rather than a hope. Validating them here as well would be a second definition of three rules whose
 * whole point is having one.
 *
 * The two id lists are `present` rather than `sometimes`: a form that stops sending a key is
 * indistinguishable from a form sending an empty list, and the two mean opposite things to a
 * replace-whole endpoint.
 */
class ClinicAttendeesRequest extends FormRequest
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
            // Offered and validated from ONE list, the `Clinic::SESSIONS` idiom applied to modes.
            'mode' => ['required', 'string', Rule::in(array_keys(Clinic::ATTENDEE_MODES))],
            'level_ids' => ['present', 'array', 'max:50'],
            'level_ids.*' => ['integer', ClinicPickers::levelRule()],
            'person_ids' => ['present', 'array', 'max:200'],
            'person_ids.*' => ['integer', ClinicPickers::personRule()],
        ];
    }

    /**
     * The rows in the shape `ClinicWriter::setAttendees()` takes, built from whichever list the
     * chosen mode is about.
     *
     * `rotators` returns the empty set deliberately rather than ignoring whatever was sent: the
     * writer REFUSES a non-empty set in that mode, and a leftover rule nothing reads is a rule
     * everything could start reading.
     *
     * @return list<array<string, int>>
     */
    public function attendeeRows(): array
    {
        $mode = (string) $this->validated()['mode'];

        $column = match ($mode) {
            Clinic::MODE_LEVELS => 'level_id',
            Clinic::MODE_NAMED => 'person_id',
            default => null,
        };

        if ($column === null) {
            return [];
        }

        $ids = $mode === Clinic::MODE_LEVELS
            ? $this->validated()['level_ids']
            : $this->validated()['person_ids'];

        return array_values(array_map(
            fn ($id): array => [$column => (int) $id],
            $ids,
        ));
    }
}
