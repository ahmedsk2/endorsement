<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\CalendarSettingsRequest;
use App\Models\AuditLog;
use App\Models\Institution;
use App\Models\Period;
use App\Support\Calendar;
use Illuminate\Http\RedirectResponse;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Admin → Structure → Calendar (cap:structure.manage). Munawib ST-02, the settings surface for
 * AR-08's calendar configuration (hijri display/offset, weekend days, MR-01's period system).
 *
 * FINDING 1, made real here: `Calendar::settings()`/`::activeHolidays()` are memoised in
 * statics for the life of the process, and `Calendar::flush()` had NO production caller before
 * this controller. Every write below calls it in the SAME request, right after the save — the
 * source-level guard is `tests/Feature/Build/CalendarWritersFlushTest.php`, and
 * `CalendarSettingsTest::test_saving_the_offset_takes_effect_within_the_same_process` proves it
 * end to end (no test-side flush of its own).
 *
 * The bounds check (finding 2) and the month-alignment/hard-lock rules (finding 3 / Decision C,
 * Decision D) all live in `CalendarSettingsRequest` — this controller trusts the FormRequest
 * completely, the same shape every other admin write in this plan follows.
 *
 * DELIBERATELY ABSENT: a timezone field. Owner decision 3 / P1 finding 5 — the instance
 * timezone is `APP_TIMEZONE`, set at deployment, never per-department. It is surfaced as a
 * READ-ONLY display value so an operator can sanity-check it against the running container
 * without a SQL prompt, never as an editable input.
 */
class CalendarSettingsController extends Controller
{
    public function index(): Response
    {
        $institution = Institution::current();

        return Inertia::render('Admin/CalendarSettings', [
            'form' => [
                'hijri_enabled' => (bool) ($institution?->hijri_enabled ?? true),
                'hijri_offset_days' => (int) ($institution?->hijri_offset_days ?? 0),
                'weekend_days' => $institution?->weekend_days ?: [5, 6],
                'period_type' => (string) ($institution?->period_type ?? Institution::PERIOD_WEEK_BLOCKS),
                'block_weeks' => $institution?->block_weeks ?: [],
                'academic_year_start' => $institution?->academic_year_start
                    ? Calendar::ymd($institution->academic_year_start)
                    : null,
            ],
            'hijri_offset_bounds' => Institution::HIJRI_OFFSET_BOUNDS,
            'weekday_options' => [
                1 => 'Monday', 2 => 'Tuesday', 3 => 'Wednesday', 4 => 'Thursday',
                5 => 'Friday', 6 => 'Saturday', 7 => 'Sunday',
            ],
            // One list, read rather than re-typed — DepartmentSetup reports which of these is
            // in force and must not invent a second name for it.
            'period_type_options' => Institution::PERIOD_TYPES,
            // Decision D: the form disables period_type/academic_year_start the moment ANY
            // periods row exists, server-side truth mirrored client-side for the UI only.
            'locked' => Period::query()->exists(),
            // Owner decision 3 / P1 finding 5: per-INSTANCE, never per-department. Read-only.
            'instance_timezone' => Calendar::timezone(),
            // UX-04, server-rendered — Decision A: resources/js performs no date arithmetic.
            'today' => Calendar::label(Calendar::todayYmd()),
        ]);
    }

    public function update(CalendarSettingsRequest $request): RedirectResponse
    {
        $institution = Institution::current();

        if ($institution === null) {
            // D11: zero or many institutions means there is no right answer, and guessing would
            // stamp a department's calendar onto a deployment that has not been seeded.
            return back()->with('error', 'This deployment has no single active institution to '
                .'configure. Run `php artisan db:seed --force` first.');
        }

        $data = $request->validated();

        // The delta is computed BEFORE the write and named by FIELD, never by value — matches
        // UnitController/LevelController's own precedent, doubly important here since a wrong
        // Hijri offset or period boundary is exactly the kind of value that must never land in
        // the audit trail even by accident.
        $changed = array_keys(array_filter(
            $data,
            fn ($value, $key): bool => $institution->getAttribute($key) != $value,
            ARRAY_FILTER_USE_BOTH,
        ));

        $institution->fill($data)->save();

        // FINDING 1. Without this, the back() redirect below renders from the memo this same
        // process built BEFORE the save above — the admin presses Save, the row changes, and
        // the page still shows the old value.
        Calendar::flush();

        AuditLog::record(
            'calendar_settings_update',
            'keys='.(implode(',', $changed) ?: 'none'),
            $request->user()->getKey(),
            $request->ip(),
        );

        return back()->with('status', 'Calendar settings saved.');
    }
}
