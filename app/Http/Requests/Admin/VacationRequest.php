<?php

namespace App\Http\Requests\Admin;

use App\Models\Vacation;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Munawib AR-05/MR-03. Books a person's leave at `week` or `date` granularity — the writer,
 * `App\Support\Rota\VacationBooking`, snaps a `week` booking to THIS department's own week
 * (`Calendar::weekOf()`, Task 2) before it ever reaches `App\Models\Vacation`.
 *
 * `person_id` validates against the SAME predicate its picker offers from
 * (`Person::query()->active()`) — the 2026-07-26 audit's offer/write parity rule, restated here
 * (identically to `RotaCellRequest`) because `Rule::exists` runs on the raw query builder and
 * never sees `Person`'s SoftDeletes global scope.
 *
 * Dates are `date_format:Y-m-d`, never `date` — the old lenient string-to-date conversion once
 * put backdated clinical rows in production, and this codebase never re-admits that leniency.
 *
 * `granularity` validates against `Vacation`'s own constants, never a bare string literal
 * repeated here — a fourth, unhandled kind of vacation is a defect this class exists to make
 * unreachable at the edge, not just inside the model guard.
 */
class VacationRequest extends FormRequest
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
        return [
            'person_id' => ['required', 'integer', Rule::exists('people', 'id')->where(
                fn ($query) => $query->where('active', true)->whereNull('deleted_at')
            )],
            'starts_on' => ['required', 'date_format:Y-m-d'],
            'ends_on' => ['required', 'date_format:Y-m-d'],
            'granularity' => ['required', Rule::in([Vacation::GRANULARITY_WEEK, Vacation::GRANULARITY_DATE])],
        ];
    }
}
