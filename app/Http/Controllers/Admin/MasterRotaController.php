<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\RotaCellRequest;
use App\Http\Requests\Admin\RotaFillRequest;
use App\Http\Requests\Admin\VacationRequest;
use App\Models\AuditLog;
use App\Models\Period;
use App\Models\Person;
use App\Models\Unit;
use App\Models\Vacation;
use App\Support\Rota\AvailabilitySummary;
use App\Support\Rota\RotaAssignment;
use App\Support\Rota\RotaFill;
use App\Support\Rota\RotaGrid;
use App\Support\Rota\StaleFillPlanException;
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

        // No viewer argument, deliberately: `RotaGrid::forYear()` takes none since P1d-2
        // Decision C. Passing the request's user made this grid emit every colleague's email
        // and phone in its props whenever a department set `contact_visibility` to `members`
        // — and for a `people.manage` holder such as the one reading THIS screen, on the
        // default setting too. No rota surface projects a contact field for any viewer.
        $grid = $year === null ? null : RotaGrid::forYear($year);

        return Inertia::render('Admin/MasterRota', [
            'academic_years' => $years,
            'year' => $year,
            'grid' => $grid,
            // MR-07, the SAME numbers the read view shows (P1d-2 Decision B, Task 5). Not a
            // second computation and not a second query: `AvailabilitySummary::forGrid()` is a
            // pure fold over the array `RotaGrid` has already built, so the editor's measured
            // query budget is unchanged by this line. `AvailabilitySummaryParityTest` reads this
            // prop and `/rota`'s off two real responses and asserts they are identical — a
            // department reading "four on PICU in Block 3" here and "three" there would have no
            // way to know which one is the rota.
            //
            // The FULL grid, stale rows included — the editor never filters its rows, so the
            // ordering trap Decision D describes cannot bite here, but the argument is the same
            // one `RotaController` spells out and the same reason `stale_people` exists.
            'summary' => $grid === null ? null : AvailabilitySummary::forGrid($grid),
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

    /**
     * Munawib MR-06's bulk moves, PREVIEWED. Writes nothing — not a row, not an audit entry — and
     * the flash shape is `RosterImport`'s (`back()->with(...)`), so the screen never holds a stale
     * plan across a navigation.
     *
     * The plan carries its own `digest`, which the confirm posts back. That is the whole
     * preview-then-confirm contract in one field.
     */
    public function fillPreview(RotaFillRequest $request): RedirectResponse
    {
        $data = $request->validated();

        return back()->with('rota_fill_preview', RotaFill::plan(
            $data['op'],
            $this->fillSource($data),
            $data['confirmations'] ?? [],
        ));
    }

    /**
     * The confirm. `RotaFill::apply()` owns the ONE transaction and re-derives the plan inside it;
     * this method's job is the two things a controller is for — turning a refusal into a 422 on the
     * right field (P1b finding 14: a raw 500 reaching the user is a bug in the controller, not the
     * guard), and appending ONE audit row.
     *
     * THE AUDIT ROW IS WRITTEN AFTER THE TRANSACTION HAS COMMITTED (finding 8), exactly as
     * `RosterImportController::commit()` does it: `AuditLog::record()` opens its own transaction and
     * locks the chain tail, so writing from inside the fill's transaction would hold that lock for
     * the fill's whole duration for no benefit — a failed fill rolls back either way. `apply()`
     * having returned IS the commit, so the ordering is structural rather than a comment.
     *
     * ONE ROW PER OPERATION, never one per cell (P1 finding 11): 780 chain appends would serialise
     * the audit tail and put 780 findings in one `OpsAlert` body. `rota_fill` is on
     * `AuditAnomalies`' single-occurrence watch list precisely because it is one row — a single
     * confirmation that rewrote several hundred cells always deserves a human look.
     *
     * Ids and counts only. No person's name, no unit code, no period label (Decision H).
     */
    public function fill(RotaFillRequest $request): RedirectResponse
    {
        $data = $request->validated();

        try {
            $result = RotaFill::apply(
                $data['op'],
                $this->fillSource($data),
                $data['confirmations'] ?? [],
                (string) $data['digest'],
            );
        } catch (StaleFillPlanException $e) {
            throw ValidationException::withMessages(['digest' => $e->getMessage()]);
        } catch (InvalidArgumentException|RuntimeException $e) {
            throw ValidationException::withMessages(['op' => $e->getMessage()]);
        }

        if ($result['errors'] !== []) {
            throw ValidationException::withMessages(['op' => $result['errors'][0]]);
        }

        AuditLog::record(
            'rota_fill',
            "op={$result['op']}"
            .';source_person='.($result['source']['person_id'] ?? 'none')
            .';source_period='.($result['source']['period_id'] ?? 'none')
            .';target_period='.($result['source']['target_period_id'] ?? 'none')
            .';targets='.count($result['targets'])
            .';assigned='.$result['summary']['assign']
            .';replaced='.$result['summary']['replace']
            .';unchanged='.$result['summary']['unchanged']
            .';skipped='.$result['summary']['skipped'],
            $request->user()->getKey(),
            $request->ip(),
        );

        return back()->with('rota_fill_result', $result);
    }

    /**
     * The request names the source cell in HTTP terms; `RotaFill` names it in the grid's. One
     * mapping, both fill routes — never re-typed per action.
     *
     * @param  array<string, mixed>  $data
     * @return array{person_id: int|null, period_id: int|null, target_period_id: int|null}
     */
    private function fillSource(array $data): array
    {
        return [
            'person_id' => isset($data['source_person_id']) ? (int) $data['source_person_id'] : null,
            'period_id' => isset($data['source_period_id']) ? (int) $data['source_period_id'] : null,
            'target_period_id' => isset($data['target_period_id']) ? (int) $data['target_period_id'] : null,
        ];
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
