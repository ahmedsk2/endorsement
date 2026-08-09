<?php

namespace App\Http\Requests\Admin;

use App\Models\Holiday;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Munawib §30 holiday write validation.
 *
 * `month`/`day` are validated against the RULE'S OWN calendar, via `withValidator()` — a
 * blanket 1-31 in `rules()` catches the wildest input cheaply, and the cross-field check adds
 * the calendar-specific refinement: a Hijri day tops out at 30 (every Hijri month is 29 or 30
 * days; a blanket 1-30 bound is the same approach `HolidayTest`'s own fixtures assume), and a
 * Gregorian month/day pair must be a REAL calendar date (`checkdate()` against a leap year, so
 * Feb 29 recurs correctly and Feb 30 never validates in any year).
 */
class HolidayRequest extends FormRequest
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
            'calendar' => ['required', Rule::in([Holiday::GREGORIAN, Holiday::HIJRI])],
            'month' => ['required', 'integer', 'between:1,12'],
            'day' => ['required', 'integer', 'between:1,31'],
            // Compared against the SAME calendar as `calendar` (the migration's own docblock):
            // a Hijri rule's year is a Hijri year, never a Gregorian one pinned to a Hijri
            // month/day. No further bound here — a fixed year is the department's own history.
            'year' => ['nullable', 'integer', 'between:1300,2200'],
            'duration_days' => ['required', 'integer', 'between:1,60'],
            'equity_tracked' => ['required', 'boolean'],
            'active' => ['required', 'boolean'],
        ];
    }

    /**
     * The calendar-specific day bound `rules()`'s blanket 1-31 cannot express on its own —
     * mirrors `PeriodGenerator::assertMonthAligned()`'s "one rule, checked from the request"
     * shape rather than re-typing the bound twice.
     */
    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $v): void {
            $data = $v->getData();

            if (! isset($data['calendar'], $data['month'], $data['day'])) {
                return;
            }

            if ($data['calendar'] === Holiday::HIJRI) {
                if ((int) $data['day'] > 30) {
                    $v->errors()->add('day', 'A Hijri day must be between 1 and 30 — every Hijri '
                        .'month is 29 or 30 days.');
                }

                return;
            }

            if (! checkdate((int) $data['month'], (int) $data['day'], 2024)) {
                $v->errors()->add('day', 'That is not a valid Gregorian month/day combination.');
            }
        });
    }
}
