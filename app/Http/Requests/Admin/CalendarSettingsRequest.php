<?php

namespace App\Http\Requests\Admin;

use App\Models\Institution;
use App\Models\Period;
use App\Support\Calendar;
use App\Support\PeriodGenerator;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use InvalidArgumentException;

/**
 * Munawib ST-02 write validation for the calendar settings screen.
 *
 * Three findings land here:
 *
 *  - finding 2: `Institution::HIJRI_OFFSET_BOUNDS` was enforced only in `ReferenceSeeder`,
 *    never in a request. The bound is READ from the constant, never re-typed as a literal.
 *  - finding 3 / Decision C: a calendar-month period system must begin on the first of a
 *    month, or `PeriodGenerator::months()` mislabels every period. Enforced through
 *    `PeriodGenerator::assertMonthAligned()` — the SAME method the generator itself calls — so
 *    a rule written once as a validation string and once as a generator guard cannot drift.
 *  - Decision D: `period_type` and `academic_year_start` hard-lock once any `periods` row
 *    exists. Changing either after generation orphans every generated period against a year
 *    that no longer starts where they do.
 */
class CalendarSettingsRequest extends FormRequest
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
        [$min, $max] = Institution::HIJRI_OFFSET_BOUNDS;

        return [
            'hijri_enabled' => ['required', 'boolean'],
            'hijri_offset_days' => ['required', 'integer', "between:{$min},{$max}"],
            'weekend_days' => ['required', 'array', 'min:1', 'max:7'],
            'weekend_days.*' => ['integer', 'between:1,7', 'distinct'],
            'period_type' => ['required', Rule::in([Institution::PERIOD_MONTHS, Institution::PERIOD_WEEK_BLOCKS])],
            // Mirrors PeriodGenerator::validateBlockWeeks()'s own bounds (1-26 blocks, 1-8
            // weeks each) — referenced in prose here, not re-typed as a second definition; the
            // generator itself is the enforcement of record for a value actually reaching it.
            'block_weeks' => ['required_if:period_type,'.Institution::PERIOD_WEEK_BLOCKS, 'array', 'min:1', 'max:26'],
            'block_weeks.*' => ['integer', 'between:1,8'],
            'academic_year_start' => ['nullable', 'date_format:Y-m-d'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        [$min, $max] = Institution::HIJRI_OFFSET_BOUNDS;

        return [
            'hijri_offset_days.between' => "HIJRI_OFFSET_DAYS must be between {$min} and {$max}. "
                .'An offset that large is a wrong timezone or a wrong hospital, not a calibration.',
        ];
    }

    /**
     * The two cross-field rules, both of which need more than a rule string:
     *
     *  - Decision C: a months-type year must start on the first of a month, checked through
     *    PeriodGenerator::assertMonthAligned() so the generator and this form share one rule.
     *  - Decision D: period_type and academic_year_start hard-lock once any `periods` row
     *    exists. Changing either after generation orphans every generated period against a
     *    year that no longer starts where they do — the same species of unrecoverable-from-a-UI
     *    change P1 finding 6 records for the day boundary.
     */
    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $v): void {
            $data = $v->getData();

            if (($data['period_type'] ?? null) === Institution::PERIOD_MONTHS
                && ! empty($data['academic_year_start'])) {
                try {
                    PeriodGenerator::assertMonthAligned(Calendar::parse($data['academic_year_start']));
                } catch (InvalidArgumentException $e) {
                    $v->errors()->add('academic_year_start', $e->getMessage());
                }
            }

            if (! Period::query()->exists()) {
                return;
            }

            $institution = Institution::current();

            foreach (['period_type', 'academic_year_start'] as $locked) {
                $current = $locked === 'academic_year_start'
                    ? $institution?->academic_year_start?->format(Calendar::YMD)
                    : $institution?->period_type;

                if (array_key_exists($locked, $data) && (string) $data[$locked] !== (string) $current) {
                    $v->errors()->add($locked, 'Periods have already been generated against this '
                        ."setting. Delete this academic year's periods first (Structure → Periods) "
                        .'— which is itself refused while the master rota references them — then '
                        .'change it. Otherwise every generated period is orphaned against a year '
                        .'that no longer starts where they do.');
                }
            }
        });
    }
}
