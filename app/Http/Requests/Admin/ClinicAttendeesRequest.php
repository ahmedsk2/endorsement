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
 *
 * THE OFFER RULE IS ASKED OF THE LIST THE MODE ACTUALLY READS, AND OF NO OTHER (P1e-1 adversarial
 * review finding 1). Both rules once applied unconditionally, which read as belt-and-braces and was
 * a permanent lockout: `attendeeRows()` below builds from ONE list and discards the other, so the
 * discarded list was refusing requests over ids that could not reach the writer, the database or
 * the screen. Retire a training level, or deactivate a colleague — plain administrative acts — and
 * every later save of that clinic was refused under `level_ids.N` / `person_ids.N`, keys the
 * clinics screen renders no element for, so the panel stayed open and reported nothing.
 * `rotators`, which needs no rules at all, was refused by the same pair, which is what removed the
 * last state the clinic could have been repaired from.
 *
 * D9 IS UNMOVED BY THAT NARROWING, and the distinction is worth stating because it looks like a
 * relaxation. The offer and the write side still come from ONE predicate per field
 * (`ClinicPickers`), and the mode that READS a list still refuses every id that list was never
 * offered — `ClinicScreenTest::test_the_relevant_list_still_refuses_an_id_the_pickers_never_offered`
 * asserts that at `active = false`, at `deleted_at`, and on a retired level. What changed is only
 * which list is asked, not what the asking accepts. The bounds (`integer`, `max:50`, `max:200`) stay
 * on both lists unconditionally, because those bound the PAYLOAD rather than name a subject.
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
        // Read RAW and compared with `===`, never cast and never through `$request->string()`.
        // Every value in `$_POST` is a string OR AN ARRAY chosen by whoever typed the request, and
        // an identity comparison answers `false` for an array without raising anything — leaving
        // the `Rule::in` below to refuse it the negotiated way, as a 422 rather than a 500.
        $mode = $this->input('mode');

        $levelIdRules = ['integer'];
        $personIdRules = ['integer'];

        // ONE list is read per mode, and only that list is asked whether the screen offered its
        // ids. `rotators` reads neither, which is what makes it the escape from a clinic whose
        // stored rule now names a retired level or a departed colleague.
        if ($mode === Clinic::MODE_LEVELS) {
            $levelIdRules[] = ClinicPickers::levelRule();
        }

        if ($mode === Clinic::MODE_NAMED) {
            $personIdRules[] = ClinicPickers::personRule();
        }

        return [
            // Offered and validated from ONE list, the `Clinic::SESSIONS` idiom applied to modes.
            'mode' => ['required', 'string', Rule::in(array_keys(Clinic::ATTENDEE_MODES))],
            'level_ids' => ['present', 'array', 'max:50'],
            'level_ids.*' => $levelIdRules,
            'person_ids' => ['present', 'array', 'max:200'],
            'person_ids.*' => $personIdRules,
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
