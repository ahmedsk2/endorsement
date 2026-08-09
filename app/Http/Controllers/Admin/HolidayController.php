<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\HolidayRequest;
use App\Models\AuditLog;
use App\Models\Holiday;
use App\Support\Calendar;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Admin → Structure → Holidays (cap:structure.manage). Munawib §30.
 *
 * P1a built `holidays`, `Holiday::anchoredOn()` and `Calendar::holidaysOn()`/`dayType()`, and
 * P1a's own docblock says "The CRUD screen is P1b" — this is it. The resolver semantics
 * (Hijri-through-the-offset, duration counted in Gregorian days, holiday-beats-weekend,
 * excluded from `MissedDays`) are ALL ALREADY implemented and tested (`HolidayTest`); nothing
 * here changes them.
 *
 * FINDING 1, extended to the holiday memo: every write flushes `Calendar` in the same request —
 * `Calendar::activeHolidays()` is memoised exactly like `Calendar::settings()`, and
 * `tests/Feature/Build/CalendarWritersFlushTest.php`'s `Holiday::create(`/`Holiday::query()`
 * needles cover this file already.
 *
 * There is deliberately no destroy(). A holiday observed last year is history — `setActive()`
 * is the only "this rule is done" action, mirroring Unit/Level's own precedent.
 */
class HolidayController extends Controller
{
    /** How far forward to scan for "this year and next"'s concrete Gregorian date(s). */
    private const RESOLVE_WINDOW_DAYS = 750;

    private const RESOLVE_COUNT = 2;

    public function index(): Response
    {
        return Inertia::render('Admin/Holidays', [
            'holidays' => Holiday::query()
                ->orderByDesc('active')
                ->orderBy('calendar')
                ->orderBy('month')
                ->orderBy('day')
                ->get()
                ->map(fn (Holiday $h): array => $this->present($h))
                ->values()
                ->all(),
        ]);
    }

    public function store(HolidayRequest $request): RedirectResponse
    {
        $holiday = Holiday::create($request->validated() + [
            // Provenance only (D11) — never a query filter, matching Level/Period's own
            // precedent (`$request->user()?->institution_id`, not `Institution::current()`).
            'institution_id' => $request->user()?->institution_id,
        ]);

        // FINDING 1. Without this, the redirect below renders from the pre-save holiday memo.
        Calendar::flush();

        AuditLog::record(
            'holiday_create',
            $this->identity($holiday),
            $request->user()->getKey(),
            $request->ip(),
        );

        return back()->with('status', 'Holiday '.$holiday->name.' created.');
    }

    public function update(HolidayRequest $request, Holiday $holiday): RedirectResponse
    {
        $data = $request->validated();

        $changed = array_keys(array_filter(
            $data,
            fn ($value, $key): bool => $holiday->getAttribute($key) != $value,
            ARRAY_FILTER_USE_BOTH,
        ));

        $holiday->update($data);
        Calendar::flush();

        AuditLog::record(
            'holiday_update',
            $this->identity($holiday).';fields='.(implode(',', $changed) ?: 'none'),
            $request->user()->getKey(),
            $request->ip(),
        );

        return back()->with('status', 'Holiday '.$holiday->name.' updated.');
    }

    /**
     * §30 / UN-04's own convention applied here: deactivation hides FORWARD — it leaves
     * `Calendar::holidaysOn()` immediately (after the flush below) but the row, and every past
     * day it already governed, is untouched. A holiday observed last year is history.
     */
    public function setActive(Request $request, Holiday $holiday): RedirectResponse
    {
        $active = $request->validate(['active' => ['required', 'boolean']])['active'];

        $holiday->update(['active' => $active]);
        Calendar::flush();

        AuditLog::record(
            $active ? 'holiday_activate' : 'holiday_deactivate',
            $this->identity($holiday),
            $request->user()->getKey(),
            $request->ip(),
        );

        return back()->with('status', 'Holiday '.$holiday->name.($active ? ' is active.' : ' retired.'));
    }

    /**
     * Audited by id and RULE IDENTITY — never by name. A holiday name is not sensitive, but the
     * by-key habit (`SettingsController`'s own precedent) is what keeps values out of the trail
     * across the whole codebase, not just where a value happens to be PHI.
     */
    private function identity(Holiday $holiday): string
    {
        return 'holiday='.$holiday->getKey().';calendar='.$holiday->calendar.';md='.$holiday->month.'-'.$holiday->day;
    }

    /**
     * @return array<string, mixed>
     */
    private function present(Holiday $holiday): array
    {
        return [
            'id' => (int) $holiday->getKey(),
            'name' => (string) $holiday->name,
            'calendar' => (string) $holiday->calendar,
            'month' => (int) $holiday->month,
            'day' => (int) $holiday->day,
            'year' => $holiday->year,
            'duration_days' => (int) $holiday->duration_days,
            'equity_tracked' => (bool) $holiday->equity_tracked,
            'active' => (bool) $holiday->active,
            // A rule stored as "Hijri 10/1" means nothing to an administrator until they see it
            // land on a real date — resolved server-side (UX-04), never computed client-side.
            'occurrences' => $this->resolve($holiday),
        ];
    }

    /**
     * The concrete Gregorian date(s) this rule resolves to, starting from today, up to
     * RESOLVE_COUNT occurrences. Works identically for BOTH calendars — `Holiday::anchoredOn()`
     * already abstracts the calendar-specific comparison, so this only needs to walk forward
     * asking it the same question every day, exactly like `Calendar::holidaysOn()` does for a
     * single date.
     *
     * @return list<array{starts_on: array, ends_on: array}>
     */
    private function resolve(Holiday $holiday): array
    {
        $occurrences = [];
        $day = Calendar::today();

        for ($i = 0; $i <= self::RESOLVE_WINDOW_DAYS && count($occurrences) < self::RESOLVE_COUNT; $i++) {
            $candidate = $day->addDays($i);

            if ($holiday->anchoredOn($candidate)) {
                $end = $candidate->addDays(max(0, $holiday->duration_days - 1));

                $occurrences[] = [
                    'starts_on' => Calendar::label($candidate),
                    'ends_on' => Calendar::label($end),
                ];
            }
        }

        return $occurrences;
    }
}
