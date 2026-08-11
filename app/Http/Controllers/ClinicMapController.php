<?php

namespace App\Http\Controllers;

use App\Models\Clinic;
use App\Models\ClinicAttendee;
use App\Models\Level;
use App\Models\Unit;
use App\Support\Calendar;
use Illuminate\Database\Eloquent\Collection;
use Inertia\Inertia;
use Inertia\Response;

/**
 * The department's week of clinics, at `/clinics`, behind `cap:clinics.view` (Munawib CL-05,
 * P1e Decision C).
 *
 * ITS OWN CONTROLLER AND ITS OWN ROUTE GROUP, the `RotaController` shape (P1d-2 Decision A).
 * `Admin\ClinicController` sits wholly inside the `cap:structure.manage` group; a `cap:clinics.view`
 * method on it would put one class behind two capabilities and make its own docblock false. This
 * codebase has already paid for the analogous mistake one level down, and doing it with a
 * CAPABILITY rather than a payload is strictly worse: the failure mode is authorization, which does
 * not show up as a 422 in a test somebody happened to write.
 *
 * READ-ONLY AS A PROPERTY OF THE ROUTE GROUP. One route, one GET, no writer, no FormRequest.
 * `clinics.view` is seeded to every position, so anything reachable with it is reachable by the
 * whole department — `ClinicMapTest::test_every_route_behind_cap_clinics_view_is_a_get` asserts the
 * property over the ROUTER, which is what makes it hold for routes nobody has written yet, and its
 * vacuity twin asserts the group is not simply empty.
 *
 * NEVER LINK-PUBLIC. Munawib §5's own footnote names the clinic map as one of three surfaces that
 * could be exposed without a login. D7 overrides it and this route carries `auth` like every other
 * route in the platform; the override is recorded in `docs/spec/08-foundation.md`, which is where a
 * deviation belongs rather than in a plan nobody finds.
 *
 * IT SHOWS CLINICS AND SESSIONS — NO PERSON-SHAPED VALUE REACHES THESE PROPS AT ALL, which is
 * deliberately stronger than gating a projection on the viewer. A clinic is a WEEKLY RECURRENCE
 * WITH NO DATE OF ITS OWN, so there is no honest date to resolve a map cell as of:
 * `ClinicRoster::forDate()` answers for a DAY, and resolving a Tuesday cell as of today (a
 * Thursday) reports Thursday's rota with complete confidence and no error anywhere. The map
 * therefore shows the RULE rather than the roster — a mode label, plus the training-level codes for
 * a level-refined clinic, which are department structure and not people. There is consequently
 * nothing here to gate and no second projection for a reviewer to check, which is the same answer
 * `RotaGrid` reached from the other direction (P1d-2 Decision C, a live payload disclosure: the
 * grid passed the request's user to the presenter, and every colleague's reachable details shipped
 * in props that nothing rendered).
 *
 * THE COLUMNS ARE `Calendar::weekdayColumns()` AND NOTHING ELSE. Already labelled, already rotated
 * into the order THIS department's week runs in (AR-08 / ST-06): the stored integer in a clinic row
 * is absolute ISO-8601, so a department that changes its weekend re-orders every column here
 * immediately, with no stored value changing and no migration. `resources/js` receives the ordered,
 * labelled array and computes nothing — a literal array of seven day names in the screen would be a
 * second answer to "what order does the week run in", and `CalendarIsTheOnlyConverterTest` scans
 * the whole of `resources/js` for exactly that, with no allow-list.
 *
 * QUERY COST IS FIXED FOR THE WHOLE PAGE and grows with neither the roster nor the number of
 * clinics: one query for the units, one for their clinics, one eager load for the refinement rules,
 * and one for the level vocabulary those rules name. The named traps, each already paid for once
 * here: `$clinic->unit` per row (the unit is already known — it is what the clinic query filtered
 * on), a level lookup per clinic instead of once for the page, and a `ClinicRoster` resolution per
 * cell. `ClinicMapTest::test_the_map_issues_a_bounded_number_of_queries` pins it on a POPULATED
 * department, because a bound measured on an empty one only ever proves the empty case.
 */
class ClinicMapController extends Controller
{
    public function index(): Response
    {
        $columns = Calendar::weekdayColumns();

        // ACTIVE CLINIC OWNERS ONLY, and that is the whole unit filter: a unit retired UNDER a
        // clinic that already exists takes its clinics off the map with it. That differs from the
        // administrative screen on purpose — an administrator who cannot SEE a clinic cannot stop
        // it either, whereas a reader looking at a retired unit's week is reading a schedule that
        // is not running. `Unit::codes()` is never consulted and no code string appears below (D2).
        $units = Unit::query()
            ->where('clinic_owner', true)
            ->where('active', true)
            ->ordered()
            ->get();

        $clinics = $units->isEmpty()
            ? new Collection
            : Clinic::query()
                ->active()
                ->whereIn('unit_id', $units->modelKeys())
                ->with('attendees')
                ->ordered()
                ->get();

        return Inertia::render('Clinics/Map', [
            'weekdays' => $columns,
            'sessions' => Clinic::SESSIONS,
            'rows' => self::rows($units, $clinics, $columns, self::levelVocabulary($clinics)),
            // THERE IS NO DATE ON THIS SURFACE, deliberately, and it is not an omission to fix
            // later. A clinic is a weekly recurrence; the moment a date appears here somebody will
            // resolve a cell as of it, and the answer for six of the seven columns will be wrong
            // with no error anywhere. The administrative screen is where "and who is that today?"
            // is asked, of a day, through `ClinicRoster`.
        ]);
    }

    /**
     * One row per unit, each carrying a cell for every (weekday, session) pair in the department's
     * own order — present and empty rather than absent, so the screen indexes rather than tests.
     *
     * @param  Collection<int, Unit>  $units
     * @param  Collection<int, Clinic>  $clinics
     * @param  list<array{iso:int, label:string, short:string, weekend:bool}>  $columns
     * @param  array<int, string>  $levelCodes  ladder order, id => code
     * @return list<array<string, mixed>>
     */
    private static function rows(Collection $units, Collection $clinics, array $columns, array $levelCodes): array
    {
        $sessions = array_keys(Clinic::SESSIONS);
        $rows = [];

        foreach ($units as $unit) {
            $cells = [];

            // `weekdayColumns()` always yields all seven ISO days, so every key a clinic can land
            // on already exists here. The append below therefore never invents one, and no clinic
            // can be silently dropped for having a weekday the map forgot to draw.
            foreach ($columns as $column) {
                foreach ($sessions as $session) {
                    $cells[$column['iso'].'-'.$session] = [];
                }
            }

            foreach ($clinics->where('unit_id', $unit->getKey()) as $clinic) {
                $cells[((int) $clinic->weekday).'-'.((string) $clinic->session)][] = self::present($clinic, $levelCodes);
            }

            $rows[] = [
                'unit' => [
                    'id' => (int) $unit->getKey(),
                    'code' => (string) $unit->code,
                    'name' => (string) $unit->name,
                    'bar_class' => (string) ($unit->bar_class ?? Unit::DEFAULT_BAR_CLASS),
                ],
                'cells' => $cells,
            ];
        }

        return $rows;
    }

    /**
     * One clinic as the map shows it: what it is called, where it is, anything the department
     * added, and who it is FOR — as a rule, never as a roster.
     *
     * `name`, `location` and `note` are plain text stored verbatim (the `handovers.extra_fields`
     * contract, not the four sanitised clinical fields), so escaping is the screen's job and it
     * interpolates every one of them.
     *
     * @param  array<int, string>  $levelCodes
     * @return array<string, mixed>
     */
    private static function present(Clinic $clinic, array $levelCodes): array
    {
        $mode = (string) $clinic->attendee_mode;

        return [
            'id' => (int) $clinic->getKey(),
            'name' => (string) $clinic->name,
            'location' => $clinic->location,
            'note' => $clinic->note,
            'who' => (string) (Clinic::ATTENDEE_MODES[$mode] ?? $mode),
            // The level codes a `levels` refinement names, in the LADDER's order rather than the
            // order the rules happen to have been saved in. Null for the other two modes: a
            // `named` rule is a list of colleagues, and putting it on a department-wide map would
            // be naming people on a surface built to show sessions.
            'who_detail' => $mode === Clinic::MODE_LEVELS
                ? self::codesFor($clinic, $levelCodes)
                : null,
        ];
    }

    /**
     * Every training level any refinement rule on the page names, id => code, in the ladder's
     * display order — ONE query for the whole map, never one per clinic.
     *
     * Deliberately unfiltered by `active`: a level retired after a clinic was refined by it is
     * still what that rule says, and a map that silently dropped the code would show a level-refined
     * clinic as refined by nothing.
     *
     * @param  Collection<int, Clinic>  $clinics
     * @return array<int, string>
     */
    private static function levelVocabulary(Collection $clinics): array
    {
        $ids = $clinics
            ->flatMap(fn (Clinic $clinic) => $clinic->attendees)
            ->pluck('level_id')
            ->filter()
            ->map(fn ($id): int => (int) $id)
            ->unique()
            ->values()
            ->all();

        if ($ids === []) {
            return [];
        }

        $out = [];

        foreach (Level::query()->whereIn('id', $ids)->ordered()->get() as $level) {
            $out[(int) $level->getKey()] = (string) $level->code;
        }

        return $out;
    }

    /**
     * @param  array<int, string>  $levelCodes
     */
    private static function codesFor(Clinic $clinic, array $levelCodes): ?string
    {
        $ids = $clinic->attendees
            ->filter(fn (ClinicAttendee $row): bool => $row->level_id !== null)
            ->map(fn (ClinicAttendee $row): int => (int) $row->level_id)
            ->values()
            ->all();

        // `array_intersect_key` keeps the FIRST array's order, which is the ladder's — so R1 always
        // reads before R2 regardless of which rule row was written first.
        $codes = array_values(array_intersect_key($levelCodes, array_flip($ids)));

        return $codes === [] ? null : implode(', ', $codes);
    }
}
