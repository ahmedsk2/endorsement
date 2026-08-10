<?php

namespace App\Http\Requests\Admin;

use App\Support\Rota\RotaFill;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Munawib MR-06's bulk moves (P1d-2 Task 8). One request class behind BOTH fill routes — the
 * preview and the confirm take the same body, which is what makes "the commit is the plan you saw"
 * a property of one shape rather than of two that have to be kept in step.
 *
 * THERE ARE NO DATES AND NO SPANS IN THIS REQUEST, deliberately. A fill names an OPERATION and a
 * SOURCE CELL; every span it writes is read from that cell server-side by `RotaFill`. A body that
 * could carry spans would be a second, unvalidated write path into `master_rota_assignments`
 * alongside `RotaCellRequest`'s.
 *
 * `source_person_id` takes the STRICT active predicate `RotaCellRequest` applies to its two WRITE
 * routes (finding 10, D9's offer/write parity) — `Rule::exists` runs on the raw query builder and
 * never sees Eloquent's SoftDeletes global scope, so `deleted_at IS NULL` is re-stated here. A fill
 * is a write, so the strict half applies to the cell it fills FROM. Target people are checked the
 * same way inside `RotaFill`, per cell, where a failure can be reported to the operator by name of
 * reason rather than as a blanket 422.
 *
 * `digest` is required on the CONFIRM route only — the preview is what produces it. It pins the
 * commit to the plan the operator actually saw, the same discipline `RosterImportController` uses
 * to pin a commit to the bytes its preview parsed; `RotaFill::digest()` documents what it covers.
 */
class RotaFillRequest extends FormRequest
{
    /** The route already sits behind `cap:rota.manage`; nothing further to authorize here. */
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        // COPY_PERIOD is the one operation with no source PERSON — it moves a whole column — and
        // the only one with a target period. Both are required exactly where they mean something.
        $copyPeriod = $this->input('op') === RotaFill::COPY_PERIOD;

        return [
            'op' => ['required', 'string', Rule::in(RotaFill::OPERATIONS)],
            'source_person_id' => [
                $copyPeriod ? 'nullable' : 'required',
                'integer',
                Rule::exists('people', 'id')->where(
                    fn ($query) => $query->where('active', true)->whereNull('deleted_at')
                ),
            ],
            'source_period_id' => ['required', 'integer', Rule::exists('periods', 'id')],
            'target_period_id' => [
                $copyPeriod ? 'required' : 'nullable',
                'integer',
                Rule::exists('periods', 'id'),
            ],
            'confirmations' => ['sometimes', 'array'],
            'confirmations.*' => ['boolean'],
            'digest' => [
                $this->routeIs('admin.rota.fill') ? 'required' : 'nullable',
                'string',
                // sha256, hex. A wrong-length digest is a client bug and is named as one, rather
                // than reaching `hash_equals` to come back as "the rota changed" — which would send
                // the operator to re-preview a rota that never moved.
                'size:64',
            ],
        ];
    }

    /**
     * The confirmation KEYS name target cells (`"<personId>:<periodId>"`, the key `RotaFill` puts on
     * every target). A malformed key is inert — it matches no target — but silently ignoring one
     * means an operator who confirmed a split overwrite from a stale screen gets their cell skipped
     * with no indication why, which is the same class of quiet miss the whole preview exists to
     * remove.
     */
    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            $confirmations = $this->input('confirmations');

            if (! is_array($confirmations)) {
                return;
            }

            foreach (array_keys($confirmations) as $key) {
                if (! is_string($key) || preg_match('/^\d+:\d+$/', $key) !== 1) {
                    $validator->errors()->add(
                        'confirmations',
                        'A confirmation must name one target cell as "<person id>:<period id>".'
                    );

                    return;
                }
            }
        });
    }
}
