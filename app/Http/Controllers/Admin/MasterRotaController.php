<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\RotaCellRequest;
use App\Http\Requests\Admin\VacationRequest;
use App\Models\AuditLog;
use App\Models\Period;
use App\Models\Person;
use App\Models\Unit;
use App\Models\Vacation;
use App\Support\Rota\RotaAssignment;
use App\Support\Rota\RotaGrid;
use App\Support\Rota\VacationBooking;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;
use InvalidArgumentException;
use RuntimeException;

/**
 * Admin → Master Rota (cap:rota.manage). Munawib MR-02/MR-03.
 *
 * `index()` renders the grid for the requested `?year=` (Task 8's `App\Support\Rota\RotaGrid`).
 * No year, or a year with no persisted periods, still renders the screen with `grid: null` and
 * the teaching empty state Task 1 shipped — the rota's columns ARE periods (P1b), so a department
 * with none generated has nowhere to plant an assignment.
 *
 * Every write here is a thin controller: validate, delegate to the ONE writer for the table
 * (`App\Support\Rota\RotaAssignment` or `App\Support\Rota\VacationBooking`, Decision F), audit by
 * ids only (Decision H), `back()` so Inertia re-renders with fresh props. A model guard's
 * `RuntimeException`/`InvalidArgumentException` is always caught and converted to a 422 here —
 * P1b finding 14's lesson: a raw 500 reaching the user is a bug in the controller, not the guard.
 */
class MasterRotaController extends Controller
{
    public function index(Request $request): Response
    {
        $years = Period::query()
            ->select('academic_year')
            ->distinct()
            ->orderBy('academic_year')
            ->pluck('academic_year');

        $requestedYear = $request->query('year');
        $year = is_string($requestedYear) && $years->contains($requestedYear) ? $requestedYear : null;

        return Inertia::render('Admin/MasterRota', [
            'academic_years' => $years,
            'year' => $year,
            'grid' => $year === null ? null : RotaGrid::forYear($year, $request->user()),
        ]);
    }

    /** One unit for the whole period — the degenerate split (Decision B). */
    public function setCell(RotaCellRequest $request): RedirectResponse
    {
        $data = $request->validated();

        $person = Person::query()->findOrFail($data['person_id']);
        $period = Period::query()->findOrFail($data['period_id']);
        $unit = Unit::query()->findOrFail($data['unit_id']);

        try {
            $result = RotaAssignment::set($person, $period, $unit);
        } catch (RuntimeException $e) {
            throw ValidationException::withMessages(['unit_id' => $e->getMessage()]);
        }

        if ($result !== RotaAssignment::UNCHANGED) {
            AuditLog::record(
                'rota_assign',
                "person={$person->getKey()};period={$period->getKey()};unit={$unit->getKey()}",
                $request->user()->getKey(),
                $request->ip(),
            );
        }

        return back();
    }

    /**
     * MR-02's date-bounded sub-assignments. Replaces every span this person holds in this
     * period — never a merge (Decision F's writer docblock).
     */
    public function splitCell(RotaCellRequest $request): RedirectResponse
    {
        $data = $request->validated();

        $person = Person::query()->findOrFail($data['person_id']);
        $period = Period::query()->findOrFail($data['period_id']);

        try {
            RotaAssignment::split($person, $period, $data['spans']);
        } catch (InvalidArgumentException|RuntimeException $e) {
            throw ValidationException::withMessages(['spans' => $e->getMessage()]);
        }

        AuditLog::record(
            'rota_split',
            "person={$person->getKey()};period={$period->getKey()};spans=".count($data['spans']),
            $request->user()->getKey(),
            $request->ip(),
        );

        return back();
    }

    /**
     * `withTrashed()`, unlike every other action here (pre-merge finding 1). `RotaCellRequest`
     * deliberately validates DELETE against a bare `Rule::exists('people', 'id')` so a person who
     * was assigned and then deactivated — or retired — can still have their spans removed; a
     * `findOrFail()` under SoftDeletes' global scope would 404 on exactly the retired half of that
     * and leave the academic year wedged against `PeriodController::destroy()` regardless. Reading
     * a person here names nobody: `RotaAssignment::clear()` only deletes rows already keyed to
     * them.
     */
    public function clearCell(RotaCellRequest $request): RedirectResponse
    {
        $data = $request->validated();

        $person = Person::query()->withTrashed()->findOrFail($data['person_id']);
        $period = Period::query()->findOrFail($data['period_id']);

        $result = RotaAssignment::clear($person, $period);

        if ($result === RotaAssignment::CLEARED) {
            AuditLog::record(
                'rota_clear',
                "person={$person->getKey()};period={$period->getKey()}",
                $request->user()->getKey(),
                $request->ip(),
            );
        }

        return back();
    }

    /** Munawib AR-05/MR-03. Delegates to the one writer of `vacations` (Decision F, Task 6). */
    public function bookVacation(VacationRequest $request): RedirectResponse
    {
        $data = $request->validated();

        $person = Person::query()->findOrFail($data['person_id']);

        try {
            $vacation = VacationBooking::book(
                $person,
                $data['starts_on'],
                $data['ends_on'],
                $data['granularity'],
            );
        } catch (RuntimeException $e) {
            throw ValidationException::withMessages(['starts_on' => $e->getMessage()]);
        }

        AuditLog::record(
            'vacation_book',
            "person={$person->getKey()};from={$vacation->starts_on->format('Y-m-d')};"
            ."to={$vacation->ends_on->format('Y-m-d')};granularity={$vacation->granularity}",
            $request->user()->getKey(),
            $request->ip(),
        );

        return back();
    }

    public function cancelVacation(Request $request, Vacation $vacation): RedirectResponse
    {
        $personId = (int) $vacation->person_id;
        $from = $vacation->starts_on->format('Y-m-d');
        $to = $vacation->ends_on->format('Y-m-d');

        VacationBooking::cancel($vacation);

        AuditLog::record(
            'vacation_cancel',
            "person={$personId};from={$from};to={$to}",
            $request->user()->getKey(),
            $request->ip(),
        );

        return back();
    }
}
