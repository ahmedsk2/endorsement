<?php

namespace App\Http\Requests\Admin;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Munawib MR-02. One request class behind THREE routes — set (PATCH), split (POST), clear
 * (DELETE) — because all three share the same `person_id`/`period_id` identity pair; only the
 * unit/span payload differs by action.
 *
 * On the two WRITE routes, `person_id` and `unit_id` validate against the SAME predicate their
 * pickers offer from (`Person::query()->active()`, `Unit::query()->active()`) — the 2026-07-26
 * audit's offer/write parity rule, restated here because `Rule::exists` runs on the raw query
 * builder and never sees Eloquent's SoftDeletes global scope (`Person` uses it; a bare
 * `Rule::exists('people', 'id')` would accept a soft-deleted id). `period_id` needs no such
 * predicate: periods carry neither an `active` flag nor a soft delete.
 *
 * CLEAR IS THE ONE ROUTE THAT DOES NOT SHARE THAT PREDICATE (pre-merge finding 1), and the
 * difference is deliberate: offer/write parity governs what may be CREATED, not what may be
 * REMOVED. People are deactivated, never deleted (standing owner ruling), so "assigned, then
 * deactivated" is a state this system reaches on its own — and with the strict predicate on DELETE
 * too, the span it left behind could not be removed through any UI. It then blocked
 * `PeriodController::destroy()` permanently, and with it Decision D's unlock of
 * `period_type`/`academic_year_start`, whose refusal message tells the operator to clear the rota
 * first. Clearing a span that already exists never names anybody, so DELETE takes a bare
 * `Rule::exists('people', 'id')` — soft-deleted ids included, since a retired person's spans wedge
 * the year exactly as an inactive one's do. `Tests\Feature\Rota\DeactivatedPersonTest` holds both
 * halves: still refused for set/split, accepted for clear.
 *
 * Dates are `date_format:Y-m-d`, never `date` — P1 finding 3: the old lenient string-to-date
 * conversion once put backdated clinical rows in production, and `Calendar::parse()` (which
 * `RotaAssignment::split()` calls on every span) is deliberately as strict.
 */
class RotaCellRequest extends FormRequest
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
        $personRule = $this->routeIs('admin.rota.cell.clear')
            ? Rule::exists('people', 'id')
            : Rule::exists('people', 'id')->where(
                fn ($query) => $query->where('active', true)->whereNull('deleted_at')
            );

        $unitRule = Rule::exists('units', 'id')->where(fn ($query) => $query->where('active', true));

        $rules = [
            'person_id' => ['required', 'integer', $personRule],
            'period_id' => ['required', 'integer', Rule::exists('periods', 'id')],
        ];

        if ($this->routeIs('admin.rota.cell.split')) {
            // `present`, not `required` — an EMPTY set reaches RotaAssignment::split(), which
            // throws its own "clear it" message (RotaAssignmentWriterTest). The split editor's
            // own "Remove span" control disables removing the last row for exactly the same
            // reason, so the UI and the exception say the same thing.
            $rules['spans'] = ['present', 'array'];
            $rules['spans.*.unit_id'] = ['required', 'integer', $unitRule];
            $rules['spans.*.starts_on'] = ['required', 'date_format:Y-m-d'];
            $rules['spans.*.ends_on'] = ['required', 'date_format:Y-m-d'];
        } elseif ($this->routeIs('admin.rota.cell') && $this->isMethod('patch')) {
            $rules['unit_id'] = ['required', 'integer', $unitRule];
        }

        return $rules;
    }
}
