<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\ClinicAttendeesRequest;
use App\Http\Requests\Admin\ClinicRequest;
use App\Models\AuditLog;
use App\Models\Clinic;
use App\Models\ClinicAttendee;
use App\Models\Level;
use App\Models\Person;
use App\Models\Unit;
use App\Support\Calendar;
use App\Support\Clinics\ClinicPickers;
use App\Support\Clinics\ClinicRoster;
use App\Support\Clinics\ClinicWriter;
use App\Support\PersonPresenter;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Inertia\Inertia;
use Inertia\Response;
use InvalidArgumentException;

/**
 * Admin → Structure → Clinics (cap:structure.manage). Munawib CL-01 and CL-02.
 *
 * Beside Units, Levels, Calendar, Periods and Holidays because a clinic's whole payload is a unit, a
 * weekday, a session and a label — that is department STRUCTURE, and `structure.manage`'s own
 * catalog description extends to it naturally. Only the department-wide clinic MAP gets a new
 * capability, because only the map is read by everybody.
 *
 * THERE IS DELIBERATELY NO destroy(). A clinic that stopped running is DEACTIVATED — UN-04's "hides
 * forward, never deletes history" — which is the same precedent `UnitController`, `LevelController`
 * and `HolidayController` each state in their own docblocks. It matters more here than it looks: a
 * P2 condition will reference a clinic by id (CL-03), and a deleted clinic takes that reference with
 * it. `ClinicWriter` exposes `setActive()` and no delete path at all, so there is nothing for a
 * destroy action to call even if one were added by hand.
 *
 * EVERY WRITE GOES THROUGH `App\Support\Clinics\ClinicWriter`. Nothing here touches a clinic model
 * directly, and `ClinicWritersAreSingularTest` fails the build for a second writer — which is not
 * belt and braces over a database constraint but the constraint itself: three rules on
 * `clinic_attendees` are inexpressible on both engines this schema runs on.
 *
 * THE WRITER'S REFUSALS ARE MESSAGES, NOT 500s. It throws for every rule the database cannot hold,
 * and it throws BEFORE its transaction opens, so catching the throw and flashing it under the field
 * leaves the clinic exactly as it was.
 *
 * NOTHING IS RESOLVED HERE EITHER. "Who does this clinic come down to today" is
 * `ClinicRoster::forDate()`, computed at read time from the master rota, never stored — moving the
 * rota moves the clinic with no write anywhere.
 *
 * WHAT REACHES THE AUDIT TRAIL IS IDS, FIELD NAMES AND COUNTS. Never a clinic's name, a unit's name
 * or a person's name: staff personal data is covered by the same rule as PHI, and a clinic's name is
 * exactly the free text a "some detail would help here" habit reaches for first.
 *
 * QUERY COST is linear in the number of CLINICS, not in the size of the department: each clinic
 * resolves through `ClinicRoster` once (two to five queries per clinic, per its own measured bound),
 * and the pickers plus the unlisted-attendee lookups are a fixed handful for the whole page. A
 * department runs a handful of clinics; if that ever stops being true, the resolution — not the
 * listing — is what to make lazy.
 */
class ClinicController extends Controller
{
    public function index(): Response
    {
        $columns = Calendar::weekdayColumns();

        // Built ONCE and passed down. The listing needs the same two offers to work out which
        // attached level or person the pickers will no longer show, and asking for them a second
        // time would run the whole roster query twice on every page load.
        $levels = ClinicPickers::levels();
        $people = ClinicPickers::people();

        return Inertia::render('Admin/Clinics', [
            'clinics' => $this->groupedClinics($columns, $levels, $people),
            // The units a clinic may be created on or moved to. Offered from the same predicate the
            // FormRequest accepts (D9) — never a code list, never a hardcoded array.
            'units' => ClinicPickers::units(),
            // The department's week, in the order IT runs, already labelled. `resources/js` computes
            // no dates at all: this array is both the weekday picker's options and the order the
            // listing is sorted in, and a department that changes its weekend re-orders both with no
            // stored value changing.
            'weekdays' => $columns,
            'sessions' => Clinic::SESSIONS,
            'modes' => Clinic::ATTENDEE_MODES,
            'levels' => $levels,
            'people' => $people,
            'today' => Calendar::label(Calendar::todayYmd()),
        ]);
    }

    /** CL-01 create. Always opens on CL-02's default mode; refining it is a second, explicit act. */
    public function store(ClinicRequest $request): RedirectResponse
    {
        $data = $request->validated();
        $unit = Unit::query()->findOrFail($data['unit_id']);

        try {
            $clinic = ClinicWriter::create($unit, $data, $request->user()?->institution_id);
        } catch (InvalidArgumentException $e) {
            return back()->withErrors(['name' => $e->getMessage()])->withInput();
        }

        AuditLog::record(
            'clinic_create',
            'clinic='.$clinic->getKey().';unit='.$unit->getKey(),
            $request->user()->getKey(),
            $request->ip(),
        );

        return back()->with('status', 'Clinic added.');
    }

    /** CL-01 update — rename, re-time, re-locate, and move to another clinic-owning unit. */
    public function update(ClinicRequest $request, Clinic $clinic): RedirectResponse
    {
        $data = $request->validated();
        $unit = Unit::query()->findOrFail($data['unit_id']);

        // The delta is computed BEFORE the write and named by FIELD, never by value — the
        // `UnitController::update()` precedent, and the reason it exists: a values-in-details habit
        // is how personal data eventually reaches an audit row.
        $changed = array_keys(array_filter(
            $data,
            fn ($value, $key): bool => $clinic->getAttribute($key) != $value,
            ARRAY_FILTER_USE_BOTH,
        ));

        try {
            ClinicWriter::update($clinic, $unit, $data);
        } catch (InvalidArgumentException $e) {
            return back()->withErrors(['name' => $e->getMessage()])->withInput();
        }

        AuditLog::record(
            'clinic_update',
            'clinic='.$clinic->getKey().';unit='.$unit->getKey().';fields='.(implode(',', $changed) ?: 'none'),
            $request->user()->getKey(),
            $request->ip(),
        );

        return back()->with('status', 'Clinic updated.');
    }

    /**
     * UN-04: deactivation HIDES FORWARD and never deletes. Its own endpoint rather than a field on
     * update(), so retiring a clinic is a deliberate single act with its own audit action — and so
     * it cannot ride along inside a rename the administrator thought was cosmetic.
     *
     * THE REFUSAL LANDS ON `active`, AND `Clinics.vue` RENDERS `active` — the two halves are one
     * decision, not two (ruling 41; P1e-1 adversarial review finding 3). This is the ONE endpoint
     * in the Units/Levels/Holidays/Clinics `setActive()` family that can refuse at all — the other
     * three cannot fail — and it shipped flashing under a key no element on the screen read, so
     * pressing Restart on a clinic whose unit had since been retired did nothing visible whatever.
     * A refusal nobody can see is indistinguishable from a broken button.
     *
     * There is deliberately no source-level guard behind that pairing, and the measurement is
     * recorded in ruling 49 rather than left as an omission: "every key a controller flashes is
     * rendered by SOME screen" is green on the very defect it would be built for, because
     * `errors.person_ids` is rendered by an unrelated panel on Admin → People. The property that
     * actually needs holding is per-SCREEN, and `back()` chooses its screen from the referrer at
     * runtime. So it is asserted behaviourally, in pairs, at each refusal — the PHPUnit half here
     * (`ClinicScreenTest::test_a_refused_restart_is_flashed_under_the_key_the_screen_renders`) and
     * the render half in `tests/js/Clinics.test.js`.
     */
    public function setActive(Request $request, Clinic $clinic): RedirectResponse
    {
        $active = $request->validate(['active' => ['required', 'boolean']])['active'];

        try {
            ClinicWriter::setActive($clinic, $active);
        } catch (InvalidArgumentException $e) {
            return back()->withErrors(['active' => $e->getMessage()]);
        }

        AuditLog::record(
            $active ? 'clinic_activate' : 'clinic_deactivate',
            'clinic='.$clinic->getKey(),
            $request->user()->getKey(),
            $request->ip(),
        );

        return back()->with('status', $active ? 'Clinic is running again.' : 'Clinic stopped.');
    }

    /**
     * CL-02's refinement, set WHOLE — the mode and the rows in one call, because they are one act.
     *
     * PUT rather than POST: this endpoint REPLACES the clinic's rule set, so a re-submitted form
     * converges instead of duplicating.
     */
    public function setAttendees(ClinicAttendeesRequest $request, Clinic $clinic): RedirectResponse
    {
        $mode = (string) $request->validated()['mode'];
        $rows = $request->attendeeRows();

        try {
            ClinicWriter::setAttendees($clinic, $mode, $rows);
        } catch (InvalidArgumentException $e) {
            return back()->withErrors(['mode' => $e->getMessage()]);
        }

        AuditLog::record(
            'clinic_attendees_set',
            'clinic='.$clinic->getKey().';mode='.$mode.';rules='.count($rows),
            $request->user()->getKey(),
            $request->ip(),
        );

        return back()->with('status', 'Who attends this clinic has been updated.');
    }

    // --- Projection --------------------------------------------------------------------------

    /**
     * The department's clinics, grouped by owning unit.
     *
     * EVERY UNIT THAT HAS A CLINIC APPEARS, including one that has since been retired or stopped
     * owning clinics. `ClinicWriter` refuses to CREATE or REVIVE a clinic on such a unit, which is a
     * different question from whether an existing one stays visible: an administrator who cannot SEE
     * a clinic cannot stop it either, which is UN-04's own reasoning for listing retired units on the
     * units screen.
     *
     * @param  list<array{iso:int, label:string, short:string, weekend:bool}>  $columns
     * @param  list<array<string, mixed>>  $levels  the offered levels, already built
     * @param  list<array<string, mixed>>  $people  the offered people, already built
     * @return list<array<string, mixed>>
     */
    private function groupedClinics(array $columns, array $levels, array $people): array
    {
        /** @var Collection<int, Clinic> $clinics */
        $clinics = Clinic::query()->with('attendees')->ordered()->get();

        if ($clinics->isEmpty()) {
            return [];
        }

        $units = Unit::query()
            ->whereIn('id', $clinics->pluck('unit_id')->unique()->values()->all())
            ->ordered()
            ->get();

        $unlisted = $this->unlistedAttendees($clinics, $levels, $people);

        // The order the department's week runs in, from the one module that knows it. The STORED
        // integer is absolute (ISO-8601); only presentation rotates, so a Sunday-start department
        // reads its own week without a single row changing.
        $weekOrder = array_flip(array_column($columns, 'iso'));
        $shortLabels = array_column($columns, 'short', 'iso');

        $groups = [];

        foreach ($units as $unit) {
            $own = $clinics->where('unit_id', $unit->getKey())->values()->all();

            usort($own, fn (Clinic $a, Clinic $b): int => [$weekOrder[(int) $a->weekday] ?? 99, (string) $a->session, (string) $a->name, (int) $a->getKey()]
                <=> [$weekOrder[(int) $b->weekday] ?? 99, (string) $b->session, (string) $b->name, (int) $b->getKey()]);

            $groups[] = [
                'unit' => [
                    'id' => (int) $unit->getKey(),
                    'code' => (string) $unit->code,
                    'name' => (string) $unit->name,
                    'active' => (bool) $unit->active,
                    'clinic_owner' => (bool) $unit->clinic_owner,
                    'bar_class' => (string) ($unit->bar_class ?? Unit::DEFAULT_BAR_CLASS),
                ],
                'clinics' => array_map(
                    fn (Clinic $clinic): array => $this->present($clinic, $shortLabels, $unlisted),
                    $own,
                ),
            ];
        }

        return $groups;
    }

    /**
     * @param  array<int, string>  $shortLabels
     * @param  array<string, string>  $unlisted  keyed "kind:id"
     * @return array<string, mixed>
     */
    private function present(Clinic $clinic, array $shortLabels, array $unlisted): array
    {
        $weekday = (int) $clinic->weekday;
        $session = (string) $clinic->session;
        $mode = (string) $clinic->attendee_mode;

        $levelIds = $clinic->attendees
            ->filter(fn (ClinicAttendee $row): bool => $row->level_id !== null)
            ->map(fn (ClinicAttendee $row): int => (int) $row->level_id)
            ->values()->all();

        $personIds = $clinic->attendees
            ->filter(fn (ClinicAttendee $row): bool => $row->person_id !== null)
            ->map(fn (ClinicAttendee $row): int => (int) $row->person_id)
            ->values()->all();

        $missing = [];

        foreach ($levelIds as $id) {
            if (isset($unlisted['level:'.$id])) {
                $missing[] = ['kind' => 'level', 'id' => $id, 'label' => $unlisted['level:'.$id]];
            }
        }

        foreach ($personIds as $id) {
            if (isset($unlisted['person:'.$id])) {
                $missing[] = ['kind' => 'person', 'id' => $id, 'label' => $unlisted['person:'.$id]];
            }
        }

        return [
            'id' => (int) $clinic->getKey(),
            'unit_id' => (int) $clinic->unit_id,
            'name' => (string) $clinic->name,
            'weekday' => $weekday,
            // The label is built where the week is: `resources/js` never names a day itself.
            'weekday_label' => Calendar::weekdayLabel($weekday),
            'weekday_short' => (string) ($shortLabels[$weekday] ?? ''),
            'session' => $session,
            'session_label' => (string) (Clinic::SESSIONS[$session] ?? $session),
            'location' => $clinic->location,
            'note' => $clinic->note,
            'active' => (bool) $clinic->active,
            'attendee_mode' => $mode,
            'mode_label' => (string) (Clinic::ATTENDEE_MODES[$mode] ?? $mode),
            'level_ids' => $levelIds,
            'person_ids' => $personIds,
            // Attached rules whose subject the pickers no longer offer — a retired training level, a
            // person who has left the roster. Named rather than dropped: a checkbox that silently
            // disappears takes the rule with it on the next save, and the administrator never sees
            // what they lost.
            'unlisted' => $missing,
            'resolved_today' => ClinicRoster::forDate($clinic, Calendar::todayYmd()),
        ];
    }

    /**
     * Labels for every attached level or person the pickers will NOT offer, in two queries for the
     * whole page rather than two per clinic.
     *
     * `withTrashed()` on the person side is the load-bearing half: a plain lookup drops a
     * soft-deleted person entirely, which is precisely the row that most needs naming here.
     *
     * @param  Collection<int, Clinic>  $clinics
     * @param  list<array<string, mixed>>  $levels
     * @param  list<array<string, mixed>>  $people
     * @return array<string, string> keyed "kind:id"
     */
    private function unlistedAttendees(Collection $clinics, array $levels, array $people): array
    {
        $rows = $clinics->flatMap(fn (Clinic $clinic) => $clinic->attendees);

        $levelIds = $rows->pluck('level_id')->filter()->map(fn ($id): int => (int) $id)->unique()->values()->all();
        $personIds = $rows->pluck('person_id')->filter()->map(fn ($id): int => (int) $id)->unique()->values()->all();

        $offeredLevels = array_column($levels, 'id');
        $offeredPeople = array_column($people, 'id');

        $out = [];

        $missingLevels = array_values(array_diff($levelIds, $offeredLevels));

        if ($missingLevels !== []) {
            foreach (Level::query()->whereIn('id', $missingLevels)->get() as $level) {
                $out['level:'.(int) $level->getKey()] = (string) $level->code.' — '.(string) $level->name;
            }
        }

        $missingPeople = array_values(array_diff($personIds, $offeredPeople));

        if ($missingPeople !== []) {
            // Through the presenter, like every other person-shaped value on a clinic surface —
            // PE-02 is "the ONE path from a Person to Inertia props", and a label is props.
            foreach (Person::query()->withTrashed()->whereIn('id', $missingPeople)->get() as $person) {
                $projected = PersonPresenter::contactFree($person);
                $out['person:'.(int) $person->getKey()] = (string) $projected['full_name'];
            }
        }

        return $out;
    }
}
