<?php

namespace App\Http\Controllers;

use App\Models\Period;
use App\Support\Rota\AvailabilitySummary;
use App\Support\Rota\RotaGrid;
use Illuminate\Http\Request;
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
 * NO CONTACT FIELD, FOR ANY VIEWER. `RotaGrid` projects through
 * `PersonPresenter::contactFree()` and takes no viewer at all (Decision C) — this controller
 * therefore has nothing to gate and nothing to strip, which is the point: there is no second
 * projection here for a reviewer to check.
 */
class RotaController extends Controller
{
    public function index(Request $request): Response
    {
        // The same distinct-year list the editor resolves, and the same "unrecognised year means
        // no year" handling. Two screens over one rota should not disagree about which years
        // exist, and a `?year=` naming a year with no periods must render the empty state rather
        // than a half-built grid — the rota's columns ARE periods (P1b).
        $years = Period::query()
            ->select('academic_year')
            ->distinct()
            ->orderBy('academic_year')
            ->pluck('academic_year');

        $requestedYear = $request->query('year');
        $year = is_string($requestedYear) && $years->contains($requestedYear) ? $requestedYear : null;

        $grid = $year === null ? null : RotaGrid::forYear($year);

        // THE ORDERING TRAP, AND IT IS EASY TO GET BACKWARDS (Decision D). The summary is computed
        // from the FULL grid — stale rows included — and only THEN are the rows filtered for
        // display. Filter first and `stale_assignments` silently becomes zero while the rest of the
        // summary still looks plausible; worse, `?q=` would narrow the department's availability
        // figures along with the list, making the numbers depend on what the reader typed into a
        // search box. These three statements are in this order on purpose. Do not reorder them.
        $summary = $grid === null ? null : AvailabilitySummary::forGrid($grid);

        $search = trim((string) $request->query('q', ''));
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
