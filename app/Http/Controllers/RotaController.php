<?php

namespace App\Http\Controllers;

use App\Models\Period;
use App\Support\Calendar;
use App\Support\Rota\AvailabilitySummary;
use App\Support\Rota\RotaGrid;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Inertia\Inertia;
use Inertia\Response;

/**
 * The master rota as a resident reads it (Munawib MR-05), at `/rota`, behind `cap:rota.view`.
 *
 * NOT UNDER `Admin\`, AND NOT A METHOD ON `MasterRotaController` (P1d-2 Decision A). That class
 * sits entirely inside the `cap:rota.manage` route group; a `cap:rota.view` method on it would put
 * one class behind two capabilities and make its own docblock false. This codebase has already
 * paid for the analogous mistake one level down — `RotaCellRequest` is one FormRequest behind
 * three routes and needs an explicit `routeIs()` branch plus a long docblock to stay honest about
 * which predicate applies where. Doing that with a CAPABILITY rather than a payload is strictly
 * worse: the failure mode is authorization, which does not show up as a 422 in a test somebody
 * happened to write. `EndorsementController` is the precedent for a non-admin surface every
 * account uses.
 *
 * READ-ONLY, AS A PROPERTY OF THE ROUTE GROUP. One route, one GET, no writer, no FormRequest.
 * `RotaAccessTest::test_every_route_behind_cap_rota_view_is_a_get` asserts it over the ROUTER, so
 * a future PR hanging a write endpoint off this group fails the build; an enumerated 403 case
 * would not, because it only covers routes somebody remembered to list.
 *
 * NO PUBLISH GATE (owner decision 1, 2026-08-10). `/rota` always shows the current rota. There is
 * no `status`, no `published_at`, no draft and no "visible from" date — not withheld here, not
 * present anywhere. The design doc once carried an explicit "not visible until I say so" gate as
 * an open product option; it is closed, and `RotaReadViewTest` scans these props for the shape so
 * it stays closed.
 *
 * IT LANDS A READER ON A YEAR, WHICH THE EDITOR DELIBERATELY DOES NOT (P1d-2 Task 4). `/rota`
 * with no `?year=` shows the year CONTAINING TODAY, falling back to the most recent one generated;
 * `/admin/rota` still shows "choose an academic year". The asymmetry is the point: choosing which
 * year to plan is the first decision an administrator makes, and a reader has no such decision —
 * they came to see where they are now. Nor is it simply "the latest year", which would move every
 * resident's landing page onto a mostly-empty future grid the moment an administrator generated
 * next year's periods. See `landingYear()`.
 *
 * NO CONTACT FIELD, FOR ANY VIEWER. `RotaGrid` projects through
 * `PersonPresenter::contactFree()` and takes no viewer at all (Decision C) — this controller
 * therefore has nothing to gate and nothing to strip, which is the point: there is no second
 * projection here for a reviewer to check.
 */
class RotaController extends Controller
{
    public function index(Request $request): Response
    {
        // The same distinct-year list the editor resolves. Two screens over one rota should not
        // disagree about which years exist, and a `?year=` naming a year with no periods must not
        // render a half-built grid — the rota's columns ARE periods (P1b).
        $years = Period::query()
            ->select('academic_year')
            ->distinct()
            ->orderBy('academic_year')
            ->pluck('academic_year');

        // WHERE THIS SCREEN DIVERGES FROM THE EDITOR, DELIBERATELY (P1d-2 Task 4). `/admin/rota`
        // with no `?year=` renders "choose an academic year", because choosing the year to plan is
        // the first decision an administrator makes and guessing it for them would be wrong.
        // A reader has no such decision — they came to see where they are now — so an empty screen
        // with a picker asks them a question they did not have. An unrecognised year falls here
        // too; the picker always shows which year is on screen, so nothing is silent.
        $requestedYear = $request->query('year');
        $year = is_string($requestedYear) && $years->contains($requestedYear)
            ? $requestedYear
            : self::landingYear($years);

        $grid = $year === null ? null : RotaGrid::forYear($year);

        // THE ORDERING TRAP, AND IT IS EASY TO GET BACKWARDS (Decision D). The summary is computed
        // from the FULL grid — stale rows included — and only THEN are the rows filtered for
        // display. Filter first and `stale_people` silently becomes zero while the rest of the
        // summary still looks plausible; worse, `?q=` would narrow the department's availability
        // figures along with the list, making the numbers depend on what the reader typed into a
        // search box. These three statements are in this order on purpose. Do not reorder them.
        $summary = $grid === null ? null : AvailabilitySummary::forGrid($grid);

        // EVERY QUERY VALUE IS A STRING **OR AN ARRAY**, chosen by whoever typed the URL. `q` used
        // to be cast straight to string, so `/rota?q[]=x` raised `Array to string conversion`,
        // which `HandleExceptions` promotes to an `ErrorException` and renders as a 500 — a page a
        // resident can take down by appending two characters to their own filter. Its two siblings
        // below already guarded, which is exactly what made it easy to miss. `$request->string()`
        // is not the alternative: it throws on array input too.
        $requestedSearch = $request->query('q');
        $search = is_string($requestedSearch) ? trim($requestedSearch) : '';
        $requestedLevel = $request->query('level');
        $levelId = is_numeric($requestedLevel) ? (int) $requestedLevel : null;

        if ($grid !== null) {
            $grid['rows'] = self::visibleRows($grid['rows'], $search, $levelId);
        }

        return Inertia::render('Rota', [
            'academic_years' => $years,
            'year' => $year,
            'grid' => $grid,
            'summary' => $summary,
            // Echoed back so the screen renders what the server actually applied rather than
            // re-deriving it from the URL and drifting.
            'filters' => ['q' => $search, 'level' => $levelId],
        ]);
    }

    /**
     * The year a reader lands on: the one CONTAINING TODAY, else the most recent one generated.
     *
     * Not simply the most recent, which is the shorter version of this method and the wrong one:
     * an administrator generating next year's periods in March (P1b's Periods screen exists so
     * they can) would silently move every resident's landing page onto a mostly-empty future grid
     * while the year the department is actually working still had months to run.
     *
     * ONE query, and only on a request that named no year — a `?year=` request never reaches here,
     * so the measured grid budget is unchanged for every link, bookmark and filter submission the
     * screen produces. `Calendar::todayYmd()` is the one converter (ST-06); the comparison itself
     * is the database's, between two `Y-m-d` values, and `whereDate` rather than equality for the
     * reason `Period::booted()` records — the `date` cast round-trips from MySQL as
     * `'Y-m-d 00:00:00'`, which never equals a plain `Y-m-d` string.
     *
     * @param  Collection<int, string>  $years
     */
    private static function landingYear(Collection $years): ?string
    {
        if ($years->isEmpty()) {
            return null;
        }

        $today = Calendar::todayYmd();

        $current = Period::query()
            ->whereDate('starts_on', '<=', $today)
            ->whereDate('ends_on', '>=', $today)
            ->value('academic_year');

        return $current ?? $years->last();
    }

    /**
     * MR-05's search and level filter, applied in PHP over rows the grid has already built. No
     * query: the roster is tens of people, the grid is in memory, and a `where` here would be a
     * ninth query buying nothing — while also putting the search predicate somewhere other than
     * where the rows are, which is how two definitions of "matches" start.
     *
     * @param  list<array<string, mixed>>  $rows
     * @return list<array<string, mixed>>
     */
    private static function visibleRows(array $rows, string $search, ?int $levelId): array
    {
        $needle = mb_strtolower($search);

        return array_values(array_filter($rows, function (array $row) use ($needle, $levelId): bool {
            // Decision D: a person who has left the department is hidden here, and ONLY here.
            // The editor still shows them (read-only, with a Clear control) because somebody has
            // to empty those cells before `PeriodController::destroy()` will release the year.
            // But on the read view a departed name with a unit beside it reads as current
            // staffing, and it is not. They are unreachable by search too — the filter runs over
            // the already-filtered set, so no query string surfaces them. Their occupied cells are
            // not lost: they are counted in the summary computed above this call.
            if ($row['stale'] ?? false) {
                return false;
            }

            // `group_level_id` is the level held at the ACADEMIC YEAR's start — the row's group,
            // which is what the screen's level `<select>` names. A row whose person has no level
            // span at all carries null and matches no filter, which is correct: they are not in
            // the group being asked for.
            if ($levelId !== null && (int) ($row['group_level_id'] ?? 0) !== $levelId) {
                return false;
            }

            if ($needle === '') {
                return true;
            }

            // Case-insensitive substring over both human handles. `short_name` is nullable.
            $haystack = mb_strtolower(
                ((string) $row['person']['full_name']).' '.((string) ($row['person']['short_name'] ?? ''))
            );

            return str_contains($haystack, $needle);
        }));
    }
}
